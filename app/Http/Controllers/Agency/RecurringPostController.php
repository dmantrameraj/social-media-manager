<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\AuditLogger;
use App\Domain\Customers\Models\Customer;
use App\Domain\Publishing\Enums\RecurrenceFrequency;
use App\Domain\Publishing\Models\RecurringPostRule;
use App\Domain\Publishing\Services\MaterialiseRecurringPostsService;
use App\Domain\Social\Models\SocialAccount;
use App\Http\Requests\Agency\StoreRecurringPostRuleRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Standing rules: "post this every Tuesday at nine."
 *
 * The rule is a template plus a cadence and publishes nothing itself. Every
 * occurrence becomes an ordinary post that takes the same workflow, approval
 * gate and plan limit as one somebody typed -- see
 * MaterialiseRecurringPostsService, which is what actually produces them.
 *
 * Gated on the existing posts.* permissions rather than a new pair of keys. A
 * recurring rule is a way of creating posts, and inventing
 * `posts.recurring.manage` would add two more catalogue entries to a
 * repository that has just finished deleting three dead ones.
 */
final class RecurringPostController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, MaterialiseRecurringPostsService $materialiser): View
    {
        $request->user()->can('posts.view') || abort(403);

        $brandIds = $this->visibleBrands($request)->pluck('id');

        $rules = RecurringPostRule::query()
            ->whereIn('customer_id', $brandIds)
            ->with('customer', 'accounts')
            ->withCount('posts')
            ->orderByDesc('id')
            ->get();

        return view('agency.posts.recurring.index', [
            'title' => 'Recurring posts',
            'rules' => $rules,
            /*
             | The next few dates each rule will fire on.
             |
             | "Every 2 weeks on Tue, Thu" is a sentence people misread, and a
             | list that only restates the cadence back at them does not help.
             | Computed from the same method the materialiser uses, so the
             | screen cannot claim a date the generator would not produce.
             */
            'upcoming' => $rules->mapWithKeys(
                fn (RecurringPostRule $rule): array => [
                    $rule->getKey() => $this->upcoming($rule, $materialiser),
                ],
            ),
        ]);
    }

    public function create(Request $request): View
    {
        $request->user()->can('posts.create') || abort(403);

        $brands = $this->visibleBrands($request);

        return view('agency.posts.recurring.create', [
            'title' => 'New recurring post',
            'brands' => $brands,
            // Only publishable destinations are offered. An account behind an
            // expired connection would fail at publish time, so it is not
            // presented as a choice.
            'accounts' => SocialAccount::query()
                ->publishable()
                ->whereIn('customer_id', $brands->pluck('id'))
                ->orderBy('name')
                ->get(),
            'frequencies' => RecurrenceFrequency::cases(),
            'horizon' => (int) config('publishing.recurrence_horizon_days', 60),
        ]);
    }

    public function store(
        StoreRecurringPostRuleRequest $request,
        MaterialiseRecurringPostsService $materialiser,
    ): RedirectResponse {
        $brand = Customer::query()->findOrFail($request->integer('customer_id'));

        // The brand id arrives from a form. Tenancy scopes it to this agency;
        // this scopes it to the brands THIS user may post to.
        $request->user()->can('view', $brand) || abort(403);

        $frequency = RecurrenceFrequency::from((string) $request->string('frequency'));

        $accounts = $this->resolveAccounts($request->input('accounts', []), $brand);

        $rule = DB::transaction(function () use ($request, $brand, $frequency, $accounts): RecurringPostRule {
            $rule = new RecurringPostRule;
            $rule->tenant_id = $brand->tenant_id;
            $rule->customer_id = $brand->getKey();
            $rule->created_by_user_id = $request->user()->getKey();
            $rule->name = (string) $request->string('name');
            $rule->title = $request->input('title');
            $rule->body = (string) $request->string('body');
            $rule->frequency = $frequency;
            $rule->interval = $request->integer('interval');

            /*
             | Only the fields this frequency uses are stored. Keeping a stale
             | day_of_month on a rule that has been switched to weekly is how a
             | later edit resurrects a cadence nobody chose.
             */
            $rule->weekdays = $frequency->usesWeekdays()
                ? array_values(array_unique(array_map('intval', $request->input('weekdays', []))))
                : null;
            $rule->day_of_month = $frequency->usesDayOfMonth()
                ? $request->integer('day_of_month')
                : null;

            $rule->time_of_day = $request->string('time_of_day').':00';

            // Snapshotted from the brand, the same way a post snapshots it. A
            // brand that later moves zones must not retime a standing rule.
            $rule->timezone = $brand->effectiveTimezone();

            $rule->starts_on = $request->date('starts_on');
            $rule->ends_on = $request->date('ends_on');
            $rule->is_active = true;
            $rule->save();

            $rule->accounts()->attach(
                $accounts->mapWithKeys(fn (SocialAccount $account): array => [
                    $account->getKey() => ['tenant_id' => $rule->tenant_id],
                ])->all(),
            );

            return $rule;
        });

        $this->audit->log(
            action: 'recurring_rule.created',
            auditable: $rule,
            newValues: ['name' => $rule->name, 'cadence' => $rule->cadenceSummary()],
            actor: $request->user(),
        );

        /*
         | Materialised immediately rather than at 02:10 tomorrow.
         |
         | Somebody who has just described a cadence wants to see the posts it
         | produces, and a screen that says "come back tomorrow" is how a
         | feature gets reported as broken. The nightly command is the thing
         | that keeps the window moving; this is the first turn of it.
         */
        $result = $materialiser->execute($rule);

        return redirect()
            ->route('agency.posts.recurring.index')
            ->with('status', sprintf(
                'Rule saved. %d post(s) created, %d scheduled.',
                $result['created'],
                $result['scheduled'],
            ));
    }

    /**
     * Pause or resume.
     *
     * Pausing stops FUTURE occurrences being generated and deliberately leaves
     * the posts already made alone. Those are real content, some of it already
     * approved, and a pause that silently cancelled next week's approved post
     * would be a much bigger action than the button implies.
     */
    public function toggle(Request $request, RecurringPostRule $rule): RedirectResponse
    {
        $request->user()->can('posts.update') || abort(403);
        $request->user()->can('view', $rule->customer) || abort(403);

        if ($rule->hasEnded()) {
            return back()->with('status', 'That rule has already run its course.');
        }

        $rule->forceFill(['is_active' => ! $rule->is_active])->save();

        $this->audit->log(
            action: $rule->is_active ? 'recurring_rule.resumed' : 'recurring_rule.paused',
            auditable: $rule,
            actor: $request->user(),
        );

        return back()->with('status', $rule->is_active ? 'Rule resumed.' : 'Rule paused.');
    }

    /**
     * Delete the rule, keep what it made.
     *
     * posts.recurring_post_rule_id is nullOnDelete for this reason: deleting a
     * rule is a statement about the future. The posts it already produced are
     * content an agency wrote, scheduled and in some cases published.
     */
    public function destroy(Request $request, RecurringPostRule $rule): RedirectResponse
    {
        $request->user()->can('posts.update') || abort(403);
        $request->user()->can('view', $rule->customer) || abort(403);

        $name = $rule->name;
        $rule->delete();

        $this->audit->log(
            action: 'recurring_rule.deleted',
            auditable: $rule,
            oldValues: ['name' => $name],
            actor: $request->user(),
        );

        return redirect()
            ->route('agency.posts.recurring.index')
            ->with('status', 'Rule deleted. The posts it already created are untouched.');
    }

    /**
     * The next few dates a rule will fire on.
     *
     * @return list<string>
     */
    private function upcoming(
        RecurringPostRule $rule,
        MaterialiseRecurringPostsService $materialiser,
        int $limit = 3,
    ): array {
        $today = Carbon::now($rule->timezone)->toDateString();

        $dates = $materialiser->occurrences(
            $rule,
            Carbon::createFromFormat('Y-m-d', $today, 'UTC')->startOfDay(),
            Carbon::createFromFormat('Y-m-d', $today, 'UTC')->startOfDay()->addDays(
                (int) config('publishing.recurrence_horizon_days', 60),
            ),
        );

        return array_map(
            fn (Carbon $date): string => $date->format('D j M'),
            array_slice($dates, 0, $limit),
        );
    }

    /**
     * Accounts the author may actually post to.
     *
     * Ids arrive from a form, so brand ownership is checked here rather than
     * trusted, and publishable() keeps a disconnected account from becoming a
     * destination a rule quietly fails against every week.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, SocialAccount>
     */
    private function resolveAccounts(array $ids, Customer $brand)
    {
        if ($ids === []) {
            return new Collection;
        }

        return SocialAccount::query()
            ->publishable()
            ->where('customer_id', $brand->getKey())
            ->whereIn('id', array_map('intval', $ids))
            ->get();
    }

    /** @return Collection<int, Customer> */
    private function visibleBrands(Request $request)
    {
        $query = Customer::query()->active()->orderBy('name');

        if (! $request->user()->can('customers.view_all')) {
            $query->whereIn('id', $request->user()->assignedCustomerIds());
        }

        return $query->get();
    }
}
