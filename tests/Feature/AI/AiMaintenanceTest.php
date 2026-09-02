<?php

declare(strict_types=1);

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Features\CaptionFeature;
use App\Domain\AI\Models\AiGeneration;
use App\Domain\AI\Models\BrandBrain;
use App\Domain\AI\Providers\FakeAiProvider;
use App\Domain\AI\Services\GenerateContentService;
use App\Domain\AI\Services\SweepStaleReservationsService;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    seedPermissions();
    FakeAiProvider::reset();
    config()->set('ai.default', 'fake');

    $this->ledger = app(CreditLedger::class);
    $this->sweeper = app(SweepStaleReservationsService::class);

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    givePlanLimit($this->tenant->getKey(), 'ai.credits_per_month', 10000);
    app(EntitlementResolver::class)->forget($this->tenant);

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    BrandBrain::factory()->forCustomer($this->brand)->create();

    $this->ledger->grant($this->tenant, 100, 'Plan allowance');
    $this->service = app(GenerateContentService::class);
});

/**
 * Simulate a worker dying mid-generation: the reservation exists, the
 * generation is still pending, and nothing ever settles it.
 */
function strandReservation(int $credits = 5): AiGeneration
{
    $generation = AiGeneration::query()->forceCreate([
        'ulid' => (string) Str::ulid(),
        'tenant_id' => test()->tenant->getKey(),
        'customer_id' => test()->brand->getKey(),
        'user_id' => test()->owner->getKey(),
        'feature' => 'caption',
        'provider' => 'fake',
        'status' => 'pending',
        'created_at' => now()->subHours(2),
    ]);

    test()->ledger->reserve(
        test()->tenant, $credits, 'AI: caption', $generation,
        'reserve:'.$generation->getKey(), test()->owner->getKey(),
    );

    return $generation;
}

/*
|--------------------------------------------------------------------------
| Reservation sweeper
|--------------------------------------------------------------------------
*/

it('returns credits stranded by a dead worker', function (): void {
    strandReservation(5);

    // Held: neither spent nor available.
    expect($this->ledger->accountFor($this->tenant)->reserved)->toBe(5)
        ->and($this->ledger->accountFor($this->tenant)->available())->toBe(95);

    $result = $this->sweeper->execute();

    expect($result['swept'])->toBe(1)
        ->and($result['credits_released'])->toBe(5)
        ->and($this->ledger->accountFor($this->tenant)->reserved)->toBe(0)
        ->and($this->ledger->accountFor($this->tenant)->available())->toBe(100);
});

it('closes the swept generation with a reason', function (): void {
    $generation = strandReservation();

    $this->sweeper->execute();

    $generation->refresh();

    expect($generation->status)->toBe('failed')
        ->and($generation->error_code)->toBe('reservation_timeout');
});

it('leaves an in-flight generation alone', function (): void {
    $generation = strandReservation(5);
    // Still inside the TTL.
    $generation->forceFill(['created_at' => now()])->save();

    expect($this->sweeper->execute()['swept'])->toBe(0)
        ->and($this->ledger->accountFor($this->tenant)->reserved)->toBe(5);
});

it('does not touch a generation that already completed', function (): void {
    FakeAiProvider::willReturn('A caption.');
    $this->service->execute(new CaptionFeature, $this->brand, $this->owner);

    AiGeneration::query()->update(['created_at' => now()->subHours(2)]);

    $balanceBefore = $this->ledger->accountFor($this->tenant)->balance;

    expect($this->sweeper->execute()['swept'])->toBe(0)
        ->and($this->ledger->accountFor($this->tenant)->balance)->toBe($balanceBefore);
});

it('does not double-release when swept twice', function (): void {
    strandReservation(5);

    $this->sweeper->execute();
    $balanceAfterFirst = $this->ledger->accountFor($this->tenant)->balance;

    // Idempotent: the second pass finds nothing pending, and the idempotency
    // key would reject a repeat release anyway.
    $second = $this->sweeper->execute();

    expect($second['swept'])->toBe(0)
        ->and($this->ledger->accountFor($this->tenant)->balance)->toBe($balanceAfterFirst);
});

it('keeps the ledger reconciled after a sweep', function (): void {
    strandReservation(5);
    $this->sweeper->execute();

    expect($this->ledger->reconcile($this->tenant)['drift'])->toBe(0);
});

it('sweeps across tenants', function (): void {
    $otherOwner = User::factory()->create();
    $other = app(ProvisionTenantService::class)->execute($otherOwner, 'Other Agency');
    $this->ledger->grant($other, 50, 'Allowance');

    withoutTenantContext();
    $otherBrand = Customer::factory()->create(['tenant_id' => $other->getKey()]);

    $generation = AiGeneration::query()->forceCreate([
        'ulid' => (string) Str::ulid(),
        'tenant_id' => $other->getKey(),
        'customer_id' => $otherBrand->getKey(),
        'feature' => 'caption',
        'provider' => 'fake',
        'status' => 'pending',
        'created_at' => now()->subHours(2),
    ]);

    $this->ledger->reserve($other, 7, 'AI: caption', $generation, 'reserve:'.$generation->getKey());

    actingForTenant($this->tenant);
    strandReservation(5);

    // A scheduled sweep runs platform-wide, not per tenant.
    expect($this->sweeper->execute()['swept'])->toBe(2)
        ->and($this->ledger->accountFor($other)->reserved)->toBe(0);
});

it('is exposed as a command', function (): void {
    strandReservation(5);

    $this->artisan('ai:sweep-reservations')
        ->expectsOutputToContain('Swept 1 stale reservation')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Snapshot purge
|--------------------------------------------------------------------------
*/

it('clears snapshots past the retention window but keeps the row', function (): void {
    FakeAiProvider::willReturn('A caption.');
    $this->service->execute(new CaptionFeature, $this->brand, $this->owner);

    $generation = AiGeneration::query()->firstOrFail();
    expect($generation->response_snapshot)->not->toBeNull();

    $generation->forceFill([
        'created_at' => now()->subDays((int) config('ai.snapshot_retention_days') + 1),
    ])->save();

    $this->artisan('ai:purge-snapshots')->assertSuccessful();

    $generation->refresh();

    // The content goes; the accounting stays, so cost per tenant remains
    // measurable.
    expect($generation->request_snapshot)->toBeNull()
        ->and($generation->response_snapshot)->toBeNull()
        ->and($generation->completion_tokens)->toBeGreaterThan(0)
        ->and($generation->credits_charged)->toBeGreaterThan(0);
});

it('leaves recent snapshots in place', function (): void {
    FakeAiProvider::willReturn('A caption.');
    $this->service->execute(new CaptionFeature, $this->brand, $this->owner);

    $this->artisan('ai:purge-snapshots')->assertSuccessful();

    expect(AiGeneration::query()->firstOrFail()->response_snapshot)->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Interaction with real failures
|--------------------------------------------------------------------------
*/

it('has nothing to sweep when a failure released its own reservation', function (): void {
    FakeAiProvider::willFail();

    try {
        $this->service->execute(new CaptionFeature, $this->brand, $this->owner);
    } catch (AiProviderException) {
        // expected
    }

    AiGeneration::query()->update(['created_at' => now()->subHours(2)]);

    // The normal failure path already released; the sweeper is only for
    // workers that died before they could.
    expect($this->sweeper->execute()['credits_released'])->toBe(0)
        ->and($this->ledger->accountFor($this->tenant)->balance)->toBe(100);
});
