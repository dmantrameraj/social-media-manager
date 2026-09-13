<?php

declare(strict_types=1);

namespace App\Domain\Publishing\Services;

use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\RecurrenceFrequency;
use App\Domain\Publishing\Exceptions\UnauthorizedTransition;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Models\RecurringPostRule;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Turns a recurring rule into concrete posts, a bounded window ahead.
 *
 * docs/06-PUBLISHING-ENGINE.md §11: "generates concrete posts a bounded window
 * ahead. Never generate infinite future rows." The horizon is the whole design
 * -- a rule with no end date is the normal case, and materialising one eagerly
 * would mean a calendar with rows in it for ever.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO
 *
 * It does not publish. Every occurrence becomes an ordinary post that takes the
 * same workflow, the same approval gate and the same plan limit as one somebody
 * typed. A rule that published directly would be a second publishing path, and
 * the second path is always the one that forgets a rule the first one enforces
 * -- which is why scheduling here goes through PostStatusMachine rather than
 * assigning a status, and why the entitlement check is not repeated locally.
 *
 * It does not walk past an approval gate. A brand with approval_required gets
 * drafts, the same as CSV import and for the same reason.
 *
 * CADENCE IS PURE CALENDAR ARITHMETIC
 *
 * Every date decision below happens on bare dates at UTC midnight, never on the
 * rule's local wall clock. Two local midnights either side of a DST boundary
 * are 23 or 25 hours apart, so a signed float diffInDays returns 6.9583 for
 * what is plainly a week; `% 7` truncates that to 6 and the rule silently skips
 * a week in March. The timezone matters in exactly one place -- turning an
 * occurrence date plus time_of_day into a UTC scheduled_at -- and it is applied
 * there and nowhere else.
 */
final class MaterialiseRecurringPostsService
{
    public function __construct(
        private readonly PostStatusMachine $machine,
        private readonly TenantContext $context,
    ) {}

    /**
     * Every rule that is due a look, across every tenant.
     *
     * @return array{rules: int, created: int, scheduled: int, skipped: int}
     */
    public function runAll(?Carbon $now = null): array
    {
        $totals = ['rules' => 0, 'created' => 0, 'scheduled' => 0, 'skipped' => 0];

        /*
         | The spatie team id, restored afterwards.
         |
         | Roles are per tenant, and the team id is normally bound by the
         | ResolveTenant middleware -- which a scheduled command never runs.
         | Without setting it here every $actor->can('posts.schedule') below is
         | false, and the sweep quietly leaves every occurrence a draft while
         | the same code works perfectly from a controller. That is a permission
         | check answering "is the registrar configured" and reporting it as
         | "this person may not schedule".
         */
        $registrar = app(PermissionRegistrar::class);
        $previousTeam = $registrar->getPermissionsTeamId();

        try {
            $this->sweep($totals, $registrar, $now);
        } finally {
            $registrar->setPermissionsTeamId($previousTeam);
        }

        return $totals;
    }

    /**
     * @param  array{rules: int, created: int, scheduled: int, skipped: int}  $totals
     */
    private function sweep(array &$totals, PermissionRegistrar $registrar, ?Carbon $now): void
    {
        RecurringPostRule::query()
            ->acrossTenants()
            ->materialisable($now)
            ->with('customer', 'author')
            ->orderBy('id')
            ->chunkById(50, function (Collection $rules) use (&$totals, $registrar, $now): void {
                foreach ($rules as $rule) {
                    $tenant = Tenant::query()->find($rule->tenant_id);

                    if ($tenant === null) {
                        continue;
                    }

                    $totals['rules']++;

                    // Roles are per tenant, so this moves with the context.
                    $registrar->setPermissionsTeamId($tenant->getKey());

                    /*
                     | Inside the rule's own tenant, so that everything it
                     | touches -- posts, targets, the status machine's audit
                     | entries -- is written under the scope it belongs to
                     | rather than whatever the sweeper happened to bypass.
                     */
                    $result = $this->context->run(
                        $tenant,
                        fn (): array => $this->safely($rule, $now),
                    );

                    foreach (['created', 'scheduled', 'skipped'] as $key) {
                        $totals[$key] += $result[$key];
                    }
                }
            });
    }

    /**
     * One rule, with its failures contained.
     *
     * A rule that throws must not take the other tenants' rules down with it.
     * The sweep runs unattended; a single bad row stopping every agency's
     * recurring content is the failure mode worth spending a try/catch on.
     *
     * @return array{created: int, scheduled: int, skipped: int}
     */
    private function safely(RecurringPostRule $rule, ?Carbon $now): array
    {
        try {
            return $this->execute($rule, $now);
        } catch (Throwable $e) {
            report($e);

            return ['created' => 0, 'scheduled' => 0, 'skipped' => 0];
        }
    }

    /**
     * @return array{created: int, scheduled: int, skipped: int}
     */
    public function execute(RecurringPostRule $rule, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();

        // "Today" where the brand is. A rule in Auckland is a day ahead of the
        // server, and its occurrence for that day is not in the past.
        $today = $this->date($now->copy()->setTimezone($rule->timezone)->toDateString());

        $horizon = $today->copy()
            ->addDays((int) config('publishing.recurrence_horizon_days', 60));

        /*
         | Resume from where we left off, not from starts_on.
         |
         | A rule that has run for a year would otherwise recompute a year of
         | occurrences on every pass, find them all already created and throw
         | the work away -- once per rule, per day, for ever.
         */
        $from = $rule->materialised_through !== null
            ? $this->date($rule->materialised_through->toDateString())->addDay()
            : $this->date($rule->starts_on->toDateString());

        /*
         | Never backfill. A rule created today does not owe anybody the posts
         | it "would have" made last month, and generating them would drop a
         | pile of overdue content onto somebody's calendar -- with scheduled_at
         | in the past, which the engine reads as "publish immediately".
         */
        if ($from->lt($today)) {
            $from = $today->copy();
        }

        $accounts = $rule->accounts()->publishable()->get();

        $created = 0;
        $scheduled = 0;
        $skipped = 0;

        foreach ($this->occurrences($rule, $from, $horizon) as $date) {
            $post = $this->materialise($rule, $date, $accounts, $now);

            if ($post === null) {
                $skipped++;

                continue;
            }

            $created++;

            if ($this->schedule($rule, $post)) {
                $scheduled++;
            }
        }

        /*
         | Recorded even when nothing was created: the marker means "this window
         | has been considered", and a rule whose cadence produces no dates in
         | the window still had its window considered.
         */
        $rule->forceFill(['materialised_through' => $horizon->toDateString()])->save();

        return ['created' => $created, 'scheduled' => $scheduled, 'skipped' => $skipped];
    }

    /**
     * The dates this rule fires on, within a window.
     *
     * Both bounds are bare dates. Public because the rules list shows the next
     * few dates beside each rule -- "every 2 weeks on Tue, Thu" is a sentence
     * people misread, and reading it back at them does not help them check.
     * Sharing this method is what stops the screen claiming a date the
     * generator would not actually produce.
     *
     * @return list<Carbon>
     */
    public function occurrences(RecurringPostRule $rule, Carbon $from, Carbon $to): array
    {
        $start = $this->date($rule->starts_on->toDateString());
        $end = $to->copy();

        if ($rule->ends_on !== null) {
            $endsOn = $this->date($rule->ends_on->toDateString());

            if ($endsOn->lt($end)) {
                $end = $endsOn;
            }
        }

        $cursor = $from->lt($start) ? $start->copy() : $from->copy();
        $dates = [];

        /*
         | A ceiling on iterations as well as on dates. The arithmetic below
         | should always terminate; a bounded loop that very occasionally stops
         | early is a far better failure than a worker that never returns.
         */
        $guard = 0;

        while ($cursor->lte($end) && $guard++ < 3650) {
            if ($this->fires($rule, $cursor, $start)) {
                $dates[] = $cursor->copy();
            }

            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * Does this rule fire on this date?
     *
     * Both arguments are bare dates at UTC midnight, so every difference here
     * is a whole number of days and no DST boundary can round one down.
     */
    private function fires(RecurringPostRule $rule, Carbon $date, Carbon $start): bool
    {
        $interval = max($rule->interval, 1);

        return match ($rule->frequency) {
            // Counted from the start date, so "every 3 days" means every third
            // day from when the rule began, not from an arbitrary epoch.
            RecurrenceFrequency::Daily => $this->days($start, $date) % $interval === 0,

            RecurrenceFrequency::Weekly => in_array($date->dayOfWeekIso, $rule->weekdays ?? [], true)
                && intdiv(
                    $this->days($start->copy()->startOfWeek(), $date->copy()->startOfWeek()),
                    7,
                ) % $interval === 0,

            RecurrenceFrequency::Monthly => $date->day === $this->monthDayFor($rule, $date)
                && $this->months($start, $date) % $interval === 0,
        };
    }

    /**
     * The day a monthly rule fires on, clamped to the length of the month.
     *
     * A rule set to the 31st must still fire in February. Skipping the month
     * would silently drop content an agency believes is scheduled, which is the
     * failure nobody notices until a client asks where the post went.
     */
    private function monthDayFor(RecurringPostRule $rule, Carbon $date): int
    {
        return min($rule->day_of_month ?? 1, $date->daysInMonth);
    }

    /**
     * One occurrence, as a post.
     *
     * Returns null when there is nothing to create, which is what makes running
     * this twice harmless.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     */
    private function materialise(
        RecurringPostRule $rule,
        Carbon $date,
        Collection $accounts,
        Carbon $now,
    ): ?Post {
        $occurrence = $date->toDateString();

        /*
         | withTrashed matters. A post somebody deliberately deleted must not
         | reappear on the next pass -- that is the rule arguing with a person,
         | and the person is right. The unique index on (rule, occurrence_date)
         | is the same guarantee at the database level, for two workers racing.
         */
        $exists = Post::query()
            ->withTrashed()
            ->where('recurring_post_rule_id', $rule->getKey())
            ->whereDate('occurrence_date', $occurrence)
            ->exists();

        if ($exists) {
            return null;
        }

        // The one place the timezone is applied: a wall-clock time on a date,
        // in the rule's own zone, stored as UTC.
        $when = Carbon::parse(
            $occurrence.' '.Carbon::parse($rule->time_of_day)->format('H:i:s'),
            $rule->timezone,
        )->utc();

        /*
         | Today's occurrence, if its hour has already passed, is not created.
         | A post dated in the past is one the engine picks up and publishes on
         | the next sweep -- "every day at 09:00" would fire the moment the rule
         | was saved at 14:00, which nobody asked for.
         */
        if ($when->lte($now)) {
            return null;
        }

        return DB::transaction(function () use ($rule, $occurrence, $when, $accounts): Post {
            $post = new Post;
            $post->tenant_id = $rule->tenant_id;
            $post->customer_id = $rule->customer_id;
            $post->recurring_post_rule_id = $rule->getKey();
            $post->occurrence_date = $occurrence;
            $post->created_by_user_id = $rule->created_by_user_id;
            $post->title = $rule->title;
            $post->body = $rule->body;
            // Rules carry no media, so there is nothing to derive from.
            $post->content_type = 'text';
            $post->status = PostStatus::Draft;
            $post->source = 'recurring';
            // Snapshotted from the rule, which snapshotted it from the brand.
            // A brand that later moves zones must not retime occurrences that
            // were already planned.
            $post->approval_required = $rule->customer->requiresClientApproval();
            $post->timezone = $rule->timezone;
            $post->scheduled_at = $when;
            $post->save();

            foreach ($accounts as $account) {
                $target = new PostTarget;
                $target->tenant_id = $post->tenant_id;
                $target->post_id = $post->getKey();
                $target->social_account_id = $account->getKey();
                $target->provider_key = $account->provider_key;
                $target->scheduled_at = $when;
                $target->max_attempts = (int) config('publishing.max_attempts', 3);
                $target->idempotency_key = hash(
                    'sha256',
                    $post->getKey().':'.$account->getKey().':'.Str::ulid(),
                );
                $target->save();
            }

            return $post;
        });
    }

    /**
     * Schedule the occurrence, where scheduling it is allowed.
     *
     * Through the status machine, which is what enforces the plan limit and
     * writes the approval trail. Doing it here with an attribute write would be
     * the second publishing path this class exists not to be.
     */
    private function schedule(RecurringPostRule $rule, Post $post): bool
    {
        /*
         | A brand that requires client approval gets a draft. Scheduling on its
         | behalf would mean a standing rule is a way to put content into a
         | client's feed without them ever seeing it -- which is precisely what
         | approval_required is for.
         */
        if ($post->approval_required) {
            return false;
        }

        /*
         | Attributed to whoever created the rule, and checked against their
         | permission. A standing rule is that person's continuing instruction:
         | if their right to schedule was revoked, the rule should stop
         | scheduling rather than keep acting on their behalf. When the author
         | has since been deleted the actor is null, which the machine treats as
         | a system transition -- the rule belongs to the agency, not to them.
         */
        try {
            $this->machine->transition($post, PostStatus::Scheduled, $rule->author);

            return true;
        } catch (EntitlementExceeded|UnauthorizedTransition) {
            /*
             | Not an error, and emphatically not a reason to abort the run. The
             | post exists as a draft, every other occurrence still gets made,
             | and somebody can schedule it by hand. A tenant at their monthly
             | limit should find their content waiting, not missing.
             */
            return false;
        }
    }

    /** A bare date at UTC midnight, safe to do arithmetic on. */
    private function date(string $date): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $date, 'UTC')->startOfDay();
    }

    /** Whole days between two bare dates. */
    private function days(Carbon $from, Carbon $to): int
    {
        return (int) round($from->diffInDays($to));
    }

    /** Whole months between two bare dates. */
    private function months(Carbon $from, Carbon $to): int
    {
        return (int) $from->diffInMonths($to);
    }
}
