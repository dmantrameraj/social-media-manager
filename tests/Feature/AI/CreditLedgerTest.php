<?php

declare(strict_types=1);

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Credits\Enums\CreditTransactionType;
use App\Domain\AI\Credits\Exceptions\InsufficientCredits;
use App\Domain\AI\Models\AiCreditTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    seedPermissions();
    $this->ledger = app(CreditLedger::class);
    $this->tenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Bright Digital');
    actingForTenant($this->tenant);
});

it('grants credits and records a transaction', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');

    $account = $this->ledger->accountFor($this->tenant);

    expect($account->balance)->toBe(100)
        ->and($account->available())->toBe(100)
        ->and(AiCreditTransaction::query()->count())->toBe(1);
});

it('reduces available without touching balance when reserving', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');
    $this->ledger->reserve($this->tenant, 30);

    $account = $this->ledger->accountFor($this->tenant);

    // The balance is untouched until the work actually completes.
    expect($account->balance)->toBe(100)
        ->and($account->reserved)->toBe(30)
        ->and($account->available())->toBe(70);
});

it('charges the actual cost on commit, not the estimate', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');
    $this->ledger->reserve($this->tenant, 30);

    // The generation turned out cheaper than estimated.
    $this->ledger->commit($this->tenant, reservedCredits: 30, actualCredits: 18);

    $account = $this->ledger->accountFor($this->tenant);

    expect($account->balance)->toBe(82)
        ->and($account->reserved)->toBe(0)
        ->and($account->available())->toBe(82);
});

it('charges nothing when a generation fails', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');
    $this->ledger->reserve($this->tenant, 30);
    $this->ledger->release($this->tenant, 30);

    $account = $this->ledger->accountFor($this->tenant);

    expect($account->balance)->toBe(100)
        ->and($account->reserved)->toBe(0)
        ->and($account->available())->toBe(100);
});

it('refuses a reservation the tenant cannot afford', function (): void {
    $this->ledger->grant($this->tenant, 10, 'Plan allowance');

    expect(fn () => $this->ledger->reserve($this->tenant, 25))
        ->toThrow(InsufficientCredits::class);

    // Nothing was held, so a later affordable request still works.
    expect($this->ledger->accountFor($this->tenant)->available())->toBe(10);
});

it('counts reservations against affordability', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');
    $this->ledger->reserve($this->tenant, 80);

    // Balance is still 100, but only 20 is actually spendable -- this is the
    // check that stops concurrent requests overspending.
    expect(fn () => $this->ledger->reserve($this->tenant, 30))
        ->toThrow(InsufficientCredits::class);
});

it('cannot be overspent by sequential reservations', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');

    $this->ledger->reserve($this->tenant, 60);
    $this->ledger->reserve($this->tenant, 40);

    expect(fn () => $this->ledger->reserve($this->tenant, 1))
        ->toThrow(InsufficientCredits::class);

    expect($this->ledger->accountFor($this->tenant)->available())->toBe(0);
});

it('rejects a duplicate idempotency key so a retry cannot double charge', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance', idempotencyKey: 'grant-abc');

    expect(fn () => $this->ledger->grant($this->tenant, 100, 'Retry', idempotencyKey: 'grant-abc'))
        ->toThrow(QueryException::class);

    expect($this->ledger->accountFor($this->tenant)->balance)->toBe(100);
});

it('records an admin adjustment with its reason', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $this->ledger->grant($this->tenant, 50, 'Plan allowance');
    $this->ledger->adjust($this->tenant, 25, 'Goodwill after outage', $admin->getKey());

    $entry = AiCreditTransaction::query()
        ->where('type', CreditTransactionType::Adjustment->value)
        ->firstOrFail();

    expect($this->ledger->accountFor($this->tenant)->balance)->toBe(75)
        ->and($entry->note)->toBe('Goodwill after outage')
        ->and($entry->user_id)->toBe($admin->getKey());
});

it('supports a negative adjustment', function (): void {
    $this->ledger->grant($this->tenant, 50, 'Plan allowance');
    $this->ledger->adjust($this->tenant, -20, 'Correcting a mistaken grant');

    expect($this->ledger->accountFor($this->tenant)->balance)->toBe(30);
});

it('rejects a zero or negative grant', function (): void {
    expect(fn () => $this->ledger->grant($this->tenant, 0, 'nope'))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $this->ledger->reserve($this->tenant, -5))
        ->toThrow(InvalidArgumentException::class);
});

/*
|--------------------------------------------------------------------------
| Monthly reset
|--------------------------------------------------------------------------
*/

it('does nothing before the period has elapsed', function (): void {
    $this->ledger->grant($this->tenant, 40, 'Plan allowance');

    expect($this->ledger->resetPeriod($this->tenant))->toBeNull()
        ->and($this->ledger->accountFor($this->tenant)->balance)->toBe(40);
});

it('resets to the monthly allowance when the period elapses', function (): void {
    $account = $this->ledger->accountFor($this->tenant);
    $account->forceFill([
        'monthly_allowance' => 500,
        'period_end' => now()->subDay(),
    ])->save();

    $this->ledger->grant($this->tenant, 40, 'Leftover');
    $this->ledger->resetPeriod($this->tenant);

    // Rollover disabled, so unused credits are not carried.
    expect($this->ledger->accountFor($this->tenant)->balance)->toBe(500);
});

it('carries unused credits when rollover is enabled, up to the cap', function (): void {
    $account = $this->ledger->accountFor($this->tenant);
    $account->forceFill([
        'monthly_allowance' => 500,
        'rollover_enabled' => true,
        'rollover_cap' => 100,
        'period_end' => now()->subDay(),
    ])->save();

    $this->ledger->grant($this->tenant, 250, 'Leftover');
    $this->ledger->resetPeriod($this->tenant);

    // 250 unused, capped at 100.
    expect($this->ledger->accountFor($this->tenant)->balance)->toBe(600);
});

it('is idempotent when the reset runs twice in one period', function (): void {
    $account = $this->ledger->accountFor($this->tenant);
    $account->forceFill([
        'monthly_allowance' => 500,
        'period_end' => now()->subDay(),
    ])->save();

    $this->ledger->resetPeriod($this->tenant);
    $second = $this->ledger->resetPeriod($this->tenant);

    // The period moved forward, so a repeat run is a no-op -- a retried
    // scheduled command must not double-grant.
    expect($second)->toBeNull()
        ->and($this->ledger->accountFor($this->tenant)->balance)->toBe(500);
});

/*
|--------------------------------------------------------------------------
| Reconciliation and isolation
|--------------------------------------------------------------------------
*/

it('reconciles the cached balance against the ledger', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');
    $this->ledger->reserve($this->tenant, 30);
    $this->ledger->commit($this->tenant, 30, 30);
    $this->ledger->adjust($this->tenant, 10, 'Goodwill');

    $result = $this->ledger->reconcile($this->tenant);

    expect($result['drift'])->toBe(0)
        ->and($result['balance'])->toBe($result['ledger'])
        ->and($result['balance'])->toBe(80);
});

it('detects drift if the cached balance is tampered with', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');

    // Simulate a direct write that bypassed the ledger.
    $this->ledger->accountFor($this->tenant)->forceFill(['balance' => 999])->save();

    expect($this->ledger->reconcile($this->tenant)['drift'])->toBe(899);
});

it('does not touch balance when reconcile finds no drift', function (): void {
    // reconcile() is read-only: three other places in this suite call it
    // after normal activity expecting the balance to be untouched.
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');

    $this->ledger->reconcile($this->tenant);

    expect($this->ledger->accountFor($this->tenant)->balance)->toBe(100);
});

it('corrects the cached balance without writing a ledger transaction', function (): void {
    /*
     | The ledger is the source of truth, so a drift means the CACHE is
     | wrong -- not that credits were gained or lost. Correcting it must not
     | create an adjustment row, which would be a real (and unexplained)
     | change to what the tenant is owed.
     */
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');
    $this->ledger->accountFor($this->tenant)->forceFill(['balance' => 999])->save();

    $before = AiCreditTransaction::query()->count();

    $result = $this->ledger->correctDrift($this->tenant);

    expect($result)->toBe(['balance' => 999, 'ledger' => 100, 'drift' => 899, 'corrected' => true])
        ->and($this->ledger->accountFor($this->tenant)->balance)->toBe(100)
        ->and(AiCreditTransaction::query()->count())->toBe($before);
});

it('does nothing when there is no drift to correct', function (): void {
    $this->ledger->grant($this->tenant, 100, 'Plan allowance');

    $result = $this->ledger->correctDrift($this->tenant);

    expect($result['corrected'])->toBeFalse()
        ->and($this->ledger->accountFor($this->tenant)->balance)->toBe(100);
});

it('keeps ledgers isolated between tenants', function (): void {
    $other = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Other Agency');

    $this->ledger->grant($this->tenant, 100, 'Ours');
    $this->ledger->grant($other, 50, 'Theirs');

    expect($this->ledger->accountFor($this->tenant)->balance)->toBe(100)
        ->and($this->ledger->accountFor($other)->balance)->toBe(50);
});

it('refuses to rewrite ledger history', function (): void {
    $entry = $this->ledger->grant($this->tenant, 100, 'Plan allowance');

    $entry->amount = 999;

    expect(fn () => $entry->save())->toThrow(RuntimeException::class);
    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});
