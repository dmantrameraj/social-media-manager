<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\CreateCustomerService;
use App\Domain\Engagement\Enums\DeliveryStatus;
use App\Domain\Engagement\Enums\InboxStatus;
use App\Domain\Engagement\Enums\MessageDirection;
use App\Domain\Engagement\Models\InboxMessage;
use App\Domain\Engagement\Models\InboxThread;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostComment;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A month of content in the demo agency, so the product has something in it.
 *
 * `DemoTenantSeeder` produces a working login with one empty brand, which is
 * the right shape for a fresh install and a poor way to look at the software:
 * every screen renders its empty state and none of them shows what it is for.
 *
 * This fills that in -- brands, connected accounts, posts across every
 * workflow state, client conversations, analytics and an inbox -- so the
 * calendar, dashboard, reports and queue all have something to draw.
 *
 * Run it after the tenant seeder:
 *
 *     php artisan db:seed --class=DemoContentSeeder
 */
final class DemoContentSeeder extends Seeder
{
    public function run(CreateCustomerService $customers): void
    {
        /*
         | Never in production, for the same reason as the tenant seeder and
         | one more: this fabricates published history and analytics. Numbers
         | that were never measured, sitting in a real agency's reports, is the
         | worst thing a seeder could do.
         */
        if (app()->isProduction()) {
            $this->command->warn('Skipping demo content: this is a production environment.');

            return;
        }

        $owner = User::query()->where('email', config('platform.demo.email', 'demo@example.test'))->first();

        if ($owner === null) {
            $this->command->error('No demo user. Run DemoTenantSeeder first.');

            return;
        }

        $tenant = Tenant::query()->whereHas('users', fn ($q) => $q->whereKey($owner->getKey()))->first();

        if ($tenant === null) {
            $this->command->error('The demo user is not a member of any agency.');

            return;
        }

        app(TenantContext::class)->set($tenant);

        $brands = $this->brands($tenant, $owner, $customers);

        foreach ($brands as $brand) {
            /*
             | Additive, never destructive, and per brand.
             |
             | A brand somebody has already written a post for is left exactly
             | as it is: a demo seeder that overwrites real work is worse than
             | one that does nothing, and the person running it is usually
             | doing so precisely because they have been clicking around.
             */
            if ($brand->posts()->exists()) {
                $this->command->line("  {$brand->name} already has posts; leaving it alone.");

                continue;
            }

            $account = $this->connectAccount($brand);
            $this->invitePortalUsers($brand);
            $this->writePosts($brand, $owner, $account);
            $this->fillInbox($brand, $account);
        }

        $this->report($brands);
    }

    /**
     * Three brands, because one brand hides every bug that involves choosing
     * the wrong one.
     *
     * @return list<Customer>
     */
    private function brands(Tenant $tenant, User $owner, CreateCustomerService $brands): array
    {
        $existing = [];

        $wanted = [
            ['Roast House Coffee', 'Food and drink', 'Europe/London'],
            ['Northwind Fitness', 'Health and fitness', 'America/New_York'],
            ['Harbour Books', 'Retail', 'Asia/Kolkata'],
        ];

        /*
         | Named brands only. Anything the operator created themselves is not
         | in this list and is therefore never touched -- not read, not
         | counted, not written to.
         */
        foreach ($wanted as [$name, $industry, $timezone]) {
            $already = Customer::query()->where('name', $name)->first();

            if ($already !== null) {
                $existing[] = $already;

                continue;
            }

            /*
             | Through the real service, exactly as DemoTenantSeeder does. It
             | assigns the slug, the settings that carry the approval
             | requirement, and the system media folders -- and it applies the
             | brands.max entitlement, so the demo cannot quietly exceed the
             | plan it is on.
             */
            $existing[] = $brands->execute($tenant->fresh(), $owner->fresh(), [
                'name' => $name,
                'industry' => $industry,
                'timezone' => $timezone,
            ]);
        }

        return $existing;
    }

    /**
     * A connected account on the fake provider.
     *
     * `FakeProvider` is registered outside production only, which is exactly
     * the environment this seeder runs in. It is what lets the demo show
     * published posts, analytics and an inbox at all -- and it is honestly
     * labelled, so nobody mistakes these for real network data.
     */
    private function connectAccount(Customer $brand): SocialAccount
    {
        return SocialAccount::factory()->forCustomer($brand)->create([
            'provider_key' => 'fake',
            'name' => $brand->name,
            'username' => Str::slug($brand->name),
        ]);
    }

    private function invitePortalUsers(Customer $brand): void
    {
        $slug = Str::slug($brand->name);

        foreach ([['approver', PortalRole::Approver], ['viewer', PortalRole::Viewer]] as [$label, $role]) {
            $user = CustomerPortalUser::factory()->create([
                'tenant_id' => $brand->tenant_id,
                'name' => ucfirst($label).' at '.$brand->name,
                'email' => "{$label}@{$slug}.test",
            ]);

            $user->customers()->attach($brand->getKey(), [
                'tenant_id' => $brand->tenant_id,
                'role' => $role->value,
            ]);
        }
    }

    /**
     * Posts across the whole workflow, spread over last month and this one.
     *
     * Statuses are set directly rather than driven through PostStatusMachine,
     * and this is deliberate on two counts. A seeder fabricates history rather
     * than making decisions, so an approval trail attributed to a real person
     * would be a lie; and every transition to Scheduled consumes the tenant's
     * real monthly allowance, so a demo would arrive at its plan limit before
     * anybody logged in.
     *
     * Matching post_approvals rows are written so the timeline is not empty.
     */
    private function writePosts(Customer $brand, User $owner, SocialAccount $account): void
    {
        $plan = [
            [PostStatus::Published, -24], [PostStatus::Published, -19],
            [PostStatus::Published, -12], [PostStatus::Published, -6],
            [PostStatus::PartiallyPublished, -3],
            [PostStatus::Failed, -2],
            [PostStatus::Scheduled, 2], [PostStatus::Scheduled, 5], [PostStatus::Scheduled, 9],
            [PostStatus::ClientReview, 4],
            [PostStatus::ManagerApproved, 6],
            [PostStatus::Rejected, 3],
            [PostStatus::Draft, null], [PostStatus::Draft, null],
            [PostStatus::Idea, null],
        ];

        foreach ($plan as $index => [$status, $offsetDays]) {
            $when = $offsetDays === null
                ? null
                : Carbon::now($brand->timezone)->addDays($offsetDays)->setTime(9 + ($index % 8), 0)->utc();

            $post = new Post;
            $post->forceFill([
                'tenant_id' => $brand->tenant_id,
                'customer_id' => $brand->getKey(),
                'created_by_user_id' => $owner->getKey(),
                'ulid' => (string) Str::ulid(),
                'title' => $this->title($brand, $index),
                'body' => $this->body($brand, $index),
                'content_type' => 'text',
                'status' => $status->value,
                'source' => $index % 7 === 0 ? 'ai' : 'manual',
                'approval_required' => true,
                'timezone' => $brand->timezone,
                'scheduled_at' => $when,
            ])->save();

            $this->recordApproval($post, $status, $owner);

            if ($when === null) {
                continue;
            }

            $target = $this->addTarget($post, $account, $status, $when);

            if ($status === PostStatus::Published || $status === PostStatus::PartiallyPublished) {
                $this->recordMetrics($target, $brand);
            }
        }

        $this->addConversation($brand, $owner);
    }

    private function addTarget(Post $post, SocialAccount $account, PostStatus $status, Carbon $when): PostTarget
    {
        $targetStatus = match ($status) {
            PostStatus::Published, PostStatus::PartiallyPublished => TargetStatus::Published,
            PostStatus::Failed => TargetStatus::Failed,
            PostStatus::Scheduled => TargetStatus::Scheduled,
            default => TargetStatus::Pending,
        };

        $target = new PostTarget;
        $target->forceFill([
            'tenant_id' => $post->tenant_id,
            'post_id' => $post->getKey(),
            'social_account_id' => $account->getKey(),
            'ulid' => (string) Str::ulid(),
            'provider_key' => $account->provider_key,
            'status' => $targetStatus->value,
            'scheduled_at' => $when,
            'published_at' => $targetStatus === TargetStatus::Published ? $when : null,
            'external_post_id' => $targetStatus === TargetStatus::Published
                ? 'fake_'.Str::random(12)
                : null,
            'attempts' => $targetStatus === TargetStatus::Failed ? 3 : 0,
            'max_attempts' => (int) config('publishing.max_attempts', 3),
            'last_error_message' => $targetStatus === TargetStatus::Failed
                ? 'The network refused the post. Reconnect the account and retry.'
                : null,
            'idempotency_key' => hash('sha256', $post->getKey().':'.$account->getKey().':'.Str::ulid()),
        ])->save();

        return $target;
    }

    /**
     * Metrics for a published target, collected twice as the real collector
     * would -- once shortly after publishing and once a few days later, so the
     * de-duplication that keeps only the latest per window has something to do.
     */
    private function recordMetrics(PostTarget $target, Customer $brand): void
    {
        foreach ([2, 6] as $daysAfter) {
            $collectedAt = $target->published_at?->copy()->addDays($daysAfter);

            if ($collectedAt === null || $collectedAt->isFuture()) {
                continue;
            }

            DB::table('post_metrics')->insert([
                'tenant_id' => $target->tenant_id,
                'post_target_id' => $target->getKey(),
                'customer_id' => $brand->getKey(),
                'social_account_id' => $target->social_account_id,
                'provider_key' => $target->provider_key,
                'impressions' => random_int(400, 9000) * $daysAfter,
                'reach' => random_int(300, 7000) * $daysAfter,
                'likes' => random_int(5, 400),
                'comments' => random_int(0, 60),
                'shares' => random_int(0, 40),
                'saves' => random_int(0, 30),
                'clicks' => random_int(0, 250),
                'video_views' => null,
                'raw' => json_encode(['source' => 'demo seeder']),
                'collected_at' => $collectedAt,
                'created_at' => $collectedAt,
                'updated_at' => $collectedAt,
            ]);
        }
    }

    /** One approval row so the post timeline is not blank. */
    private function recordApproval(Post $post, PostStatus $status, User $owner): void
    {
        if ($status === PostStatus::Idea || $status === PostStatus::Draft) {
            return;
        }

        DB::table('post_approvals')->insert([
            'tenant_id' => $post->tenant_id,
            'post_id' => $post->getKey(),
            'stage' => 'internal',
            'action' => $status === PostStatus::Rejected ? 'rejected' : 'transitioned',
            'actor_type' => 'user',
            'actor_id' => $owner->getKey(),
            'comment' => $status === PostStatus::Rejected
                ? 'The opening line reads as an advert. Soften it and send it back.'
                : null,
            'from_status' => PostStatus::Draft->value,
            'to_status' => $status->value,
            'created_at' => now()->subDays(random_int(1, 20)),
        ]);
    }

    /** A conversation with the client, and an internal note beside it. */
    private function addConversation(Customer $brand, User $owner): void
    {
        $post = Post::query()->where('customer_id', $brand->getKey())
            ->where('status', PostStatus::ClientReview->value)->first();

        if ($post === null) {
            return;
        }

        $client = CustomerPortalUser::query()
            ->whereHas('customers', fn ($q) => $q->whereKey($brand->getKey()))
            ->first();

        $comment = new PostComment;
        $comment->forceFill([
            'tenant_id' => $brand->tenant_id,
            'post_id' => $post->getKey(),
            // The ActorType discriminator, not a class name: author_type is a
            // varchar(40) holding 'user' or 'customer_portal_user'.
            'author_type' => ActorType::CustomerPortalUser->value,
            'author_id' => $client?->getKey(),
            'body' => 'Can we mention the opening hours? Otherwise this looks good.',
            'is_internal' => false,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->save();

        $note = new PostComment;
        $note->forceFill([
            'tenant_id' => $brand->tenant_id,
            'post_id' => $post->getKey(),
            'author_type' => ActorType::User->value,
            'author_id' => $owner->getKey(),
            'body' => 'They ask for hours every month. Add them to the brand brain.',
            'is_internal' => true,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->save();
    }

    /** A few conversations waiting in the queue, one of them unanswered. */
    private function fillInbox(Customer $brand, SocialAccount $account): void
    {
        /*
         | The reply state is carried in the data rather than derived from the
         | thread status. Deriving it read as though a closed thread implies a
         | failed reply, which is not true and is not what is meant: one of
         | these is unsent on purpose, so the inbox has something to warn about
         | and that path can be seen working.
         */
        $conversations = [
            ['Do you deliver to the north side?', InboxStatus::Open, null],
            ['Loved the new blend, when is it back in stock?', InboxStatus::Open, DeliveryStatus::Delivered],
            ['Is the Saturday class still running?', InboxStatus::Closed, DeliveryStatus::Failed],
        ];

        foreach ($conversations as [$question, $status, $reply]) {
            $thread = InboxThread::factory()->forAccount($account)->create([
                'status' => $status->value,
                'last_message_at' => now()->subHours(random_int(2, 60)),
            ]);

            InboxMessage::factory()->inThread($thread)->create([
                'body' => $question,
                'direction' => MessageDirection::Inbound->value,
                'posted_at' => now()->subHours(random_int(6, 72)),
            ]);

            if ($reply === null) {
                continue;
            }

            InboxMessage::factory()->inThread($thread)->create([
                'body' => 'Thanks for asking -- yes, and we can hold one back for you.',
                'direction' => MessageDirection::Outbound->value,
                'author_name' => 'Your team',
                'delivery_status' => $reply->value,
                'posted_at' => now()->subHours(random_int(1, 5)),
            ]);
        }
    }

    /**
     * Plausible copy, written per brand rather than lorem ipsum.
     *
     * A demo full of placeholder text makes every screen look the same and
     * hides the thing worth checking -- whether a long caption wraps, whether
     * a short one leaves the card looking broken.
     */
    private function title(Customer $brand, int $index): string
    {
        $titles = [
            'Roast House Coffee' => [
                'Autumn blend launch', 'Saturday cupping', 'Behind the roaster',
                'New opening hours', 'Guest bean: Yirgacheffe', 'Loyalty card relaunch',
            ],
            'Northwind Fitness' => [
                'January intake', 'Meet the coaches', 'Six-week challenge',
                'Class timetable change', 'Member story: Dana', 'Kit bag essentials',
            ],
            'Harbour Books' => [
                'Staff picks for autumn', 'Author evening', 'Signed first editions',
                'Half-term reading list', 'The poetry corner', 'Late opening Thursday',
            ],
        ];

        $set = $titles[$brand->name] ?? $titles['Roast House Coffee'];

        return $set[$index % count($set)];
    }

    private function body(Customer $brand, int $index): string
    {
        $bodies = [
            'Roast House Coffee' => [
                'The autumn blend lands on Friday. Chocolate, plum and a long finish -- our warmest cup of the year.',
                'Saturday cupping, 10am, free. Six coffees, no jargon, and you can buy whichever one you liked best.',
                "Twelve minutes and one degree is the whole difference between this roast and last month's. Sam explains.",
            ],
            'Northwind Fitness' => [
                'January intake is open. Three sessions a week, eight weeks, and a coach who remembers your name.',
                'The 6am class moves to 6:15 from Monday. Same coach, same session, fifteen more minutes in bed.',
                'Dana joined in March and could not run for a bus. She finished ten kilometres on Sunday.',
            ],
            'Harbour Books' => [
                'Autumn staff picks are on the front table. Four novels, one memoir, and something none of us can categorise.',
                'Author evening on the 14th. Tickets are free, the wine is not, and the back room holds forty.',
                'Half-term is coming. Here is the reading list we give every parent who asks, in order of stickiness.',
            ],
        ];

        $set = $bodies[$brand->name] ?? $bodies['Roast House Coffee'];

        return $set[$index % count($set)];
    }

    /** @param  list<Customer>  $brands */
    private function report(array $brands): void
    {
        $this->command->newLine();
        $this->command->info('Demo content ready:');
        $this->command->line('  Brands        '.count($brands));
        $this->command->line('  Posts         '.Post::query()->count());
        $this->command->line('  Targets       '.PostTarget::query()->count());
        $this->command->line('  Inbox threads '.InboxThread::query()->count());
        $this->command->newLine();
        $this->command->line('  Portal logins are approver@<brand-slug>.test and viewer@<brand-slug>.test.');
        $this->command->line('  Set a password with: php artisan tinker');
        $this->command->newLine();
        $this->command->warn('  Accounts and metrics are the FAKE provider. No real network was contacted.');
        $this->command->newLine();
    }
}
