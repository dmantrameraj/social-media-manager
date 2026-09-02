<?php

declare(strict_types=1);

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Models\AutopilotSetting;
use App\Domain\AI\Models\BrandBrain;
use App\Domain\AI\Providers\FakeAiProvider;
use App\Domain\AI\Services\AutopilotService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedPermissions();
    FakeAiProvider::reset();
    config()->set('ai.default', 'fake');
    config()->set('features.autopilot', true);

    $this->autopilot = app(AutopilotService::class);
    $this->ledger = app(CreditLedger::class);

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    grantAutopilotPlan($this->tenant->getKey());
    app(EntitlementResolver::class)->forget($this->tenant);

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);
    BrandBrain::factory()->forCustomer($this->brand)->create();

    $this->ledger->grant($this->tenant, 500, 'Plan allowance');
});

/** A plan that both permits autopilot and allows AI credits. */
function grantAutopilotPlan(int $tenantId): void
{
    DB::table('subscriptions')->where('tenant_id', $tenantId)->delete();

    $planId = DB::table('plans')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'name' => 'Agency',
        'slug' => 'agency-'.Str::lower(Str::random(6)),
        'is_public' => true, 'is_active' => true,
        'trial_days' => 0, 'sort_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('plan_features')->insert([
        ['plan_id' => $planId, 'key' => 'ai.autopilot', 'value_type' => 'boolean', 'value' => 1,
            'created_at' => now(), 'updated_at' => now()],
        ['plan_id' => $planId, 'key' => 'ai.credits_per_month', 'value_type' => 'limit', 'value' => 10000,
            'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('subscriptions')->insert([
        'ulid' => (string) Str::ulid(),
        'tenant_id' => $tenantId, 'plan_id' => $planId,
        'status' => 'active', 'gateway' => 'manual', 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function scriptIdeaAndCaption(): void
{
    FakeAiProvider::willReturn(json_encode([
        'ideas' => [['hook' => 'Behind the roast', 'angle' => 'Process', 'format' => 'Reel']],
    ]));
    FakeAiProvider::willReturn('Every bean, roasted this morning.');
}

/*
|--------------------------------------------------------------------------
| The constraint that matters
|--------------------------------------------------------------------------
*/

it('creates content only as a DRAFT', function (): void {
    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $this->autopilot->run();

    $post = Post::query()->firstOrFail();

    // Autopilot has no path to SCHEDULED. An agency's client did not agree to
    // let a model post on their behalf unreviewed.
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->body)->toBe('Every bean, roasted this morning.');
});

it('never produces a post past the approval gate', function (): void {
    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $this->autopilot->run();

    $statuses = Post::query()->pluck('status');

    foreach ($statuses as $status) {
        expect($status)->toBe(PostStatus::Draft);
    }

    // Nothing was approved, scheduled or published behind the workflow's back.
    expect(Post::query()->whereNotNull('approved_at')->count())->toBe(0)
        ->and(Post::query()->whereNotNull('published_at')->count())->toBe(0);
});

it('carries the client approval requirement through from the brand', function (): void {
    $this->brand->forceFill(['settings' => ['approval_required' => true]])->save();

    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $this->autopilot->run();

    expect(Post::query()->firstOrFail()->approval_required)->toBeTrue();
});

it('marks generated posts as AI in origin', function (): void {
    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $this->autopilot->run();

    // An agency must be able to tell at a glance which posts a model wrote.
    expect(Post::query()->firstOrFail()->source)->toBe('ai');
});

it('audits every autopilot creation', function (): void {
    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $this->autopilot->run();

    expect(AuditLog::query()->where('action', 'post.autopilot_created')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Opt-in and gating
|--------------------------------------------------------------------------
*/

it('does nothing for a brand that has not opted in', function (): void {
    AutopilotSetting::factory()->forCustomer($this->brand)->create();  // enabled = false
    scriptIdeaAndCaption();

    $result = $this->autopilot->run();

    expect($result['brands'])->toBe(0)
        ->and(Post::query()->count())->toBe(0)
        ->and(FakeAiProvider::callCount())->toBe(0);
});

it('does nothing for a brand whose cadence has not come around', function (): void {
    AutopilotSetting::factory()->forCustomer($this->brand)->enabled()->create([
        'next_run_at' => now()->addDay(),
    ]);
    scriptIdeaAndCaption();

    expect($this->autopilot->run()['brands'])->toBe(0)
        ->and(Post::query()->count())->toBe(0);
});

it('skips a brand whose plan does not include autopilot', function (): void {
    DB::table('plan_features')
        ->where('key', 'ai.autopilot')
        ->update(['value' => 0]);
    app(EntitlementResolver::class)->forget($this->tenant);

    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $result = $this->autopilot->run();

    expect($result['skipped'])->toBe(1)
        ->and(Post::query()->count())->toBe(0);
});

it('skips a suspended tenant rather than generating for free', function (): void {
    $this->tenant->forceFill(['status' => TenantStatus::Suspended->value])->save();

    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    expect($this->autopilot->run()['skipped'])->toBe(1)
        ->and(Post::query()->count())->toBe(0);
});

it('skips an archived brand', function (): void {
    $this->brand->forceFill(['status' => 'archived'])->save();

    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    expect($this->autopilot->run()['skipped'])->toBe(1)
        ->and(Post::query()->count())->toBe(0);
});

it('stops cleanly when the tenant runs out of credits', function (): void {
    // Drain the balance so the first reservation cannot be met.
    $this->ledger->adjust($this->tenant, -500, 'Spent');

    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $result = $this->autopilot->run();

    // Running dry is a skip, not a crash -- and the clock still advances so
    // one dry brand cannot monopolise every run.
    expect($result['skipped'])->toBe(1)
        ->and(Post::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Cadence
|--------------------------------------------------------------------------
*/

it('advances the run clock after a run', function (): void {
    $setting = AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $this->autopilot->run();

    $setting->refresh();

    expect($setting->last_run_at)->not->toBeNull()
        ->and($setting->next_run_at->isFuture())->toBeTrue();
});

it('does not re-run a brand within the same sweep window', function (): void {
    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    scriptIdeaAndCaption();

    $this->autopilot->run();
    $countAfterFirst = Post::query()->count();

    scriptIdeaAndCaption();
    $this->autopilot->run();

    // A client seeing a week of drafts appear at once reads as a malfunction.
    expect(Post::query()->count())->toBe($countAfterFirst);
});

it('spreads runs according to the configured cadence', function (): void {
    $weekly = AutopilotSetting::factory()->forCustomer($this->brand)->due()->create([
        'posts_per_week' => 1,
    ]);

    expect($weekly->intervalDays())->toBe(7.0);

    $daily = AutopilotSetting::factory()->make(['posts_per_week' => 7]);
    expect($daily->intervalDays())->toBe(1.0);
});

/*
|--------------------------------------------------------------------------
| Isolation
|--------------------------------------------------------------------------
*/

it('keeps autopilot output inside its own tenant', function (): void {
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Other Agency');
    grantAutopilotPlan($otherTenant->getKey());

    withoutTenantContext();
    $otherBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    BrandBrain::factory()->forCustomer($otherBrand)->create();
    app(CreditLedger::class)->grant($otherTenant, 500, 'Allowance');

    AutopilotSetting::factory()->forCustomer($this->brand)->due()->create();
    AutopilotSetting::factory()->forCustomer($otherBrand)->due()->create();

    scriptIdeaAndCaption();
    scriptIdeaAndCaption();

    $this->autopilot->run();

    actingForTenant($this->tenant);
    $ours = Post::query()->get();

    foreach ($ours as $post) {
        expect($post->tenant_id)->toBe($this->tenant->getKey())
            ->and($post->customer_id)->toBe($this->brand->getKey());
    }
});
