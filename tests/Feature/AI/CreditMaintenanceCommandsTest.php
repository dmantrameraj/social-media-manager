<?php

declare(strict_types=1);

use App\Domain\AI\Credits\CreditLedger;
use App\Domain\AI\Models\AiCreditAccount;
use App\Domain\AI\Services\ReconcileCreditsService;
use App\Domain\AI\Services\ResetCreditPeriodsService;
use App\Domain\Audit\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/*
 | CreditLedger::resetPeriod() and reconcile() have existed since the ledger
 | shipped. Neither was ever called for more than one tenant at a time, and
 | nothing ever scheduled either -- so no tenant's AI credits have reset on
 | schedule, and a cached balance that drifted stayed drifted indefinitely.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->ledger = app(CreditLedger::class);

    // The shared helper in tests/Pest.php -- declared locally here it would
    // be a duplicate global function, which fatals the whole suite rather
    // than just this file.
    [$this->tenantA] = provisionTenant('Agency A');
    [$this->tenantB] = provisionTenant('Agency B');
});

// ------------------------------------------------------------- monthly reset

it('resets every tenant whose period has elapsed', function (): void {
    $this->ledger->grant($this->tenantA, 40, 'Leftover');
    AiCreditAccount::query()->forTenant($this->tenantA)->update([
        'monthly_allowance' => 500,
        'period_end' => now()->subDay(),
    ]);

    // Tenant B's period is not due; it must be left alone.
    $this->ledger->grant($this->tenantB, 40, 'Leftover');

    $result = app(ResetCreditPeriodsService::class)->execute();

    expect($result)->toBe(['checked' => 1, 'reset' => 1])
        ->and($this->ledger->accountFor($this->tenantA)->balance)->toBe(500)
        ->and($this->ledger->accountFor($this->tenantB)->balance)->toBe(40);
});

it('resets more than one due tenant in a single sweep', function (): void {
    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        AiCreditAccount::query()->forTenant($tenant)->update([
            'monthly_allowance' => 300,
            'period_end' => now()->subDay(),
        ]);
    }

    $result = app(ResetCreditPeriodsService::class)->execute();

    expect($result['reset'])->toBe(2)
        ->and($this->ledger->accountFor($this->tenantA)->balance)->toBe(300)
        ->and($this->ledger->accountFor($this->tenantB)->balance)->toBe(300);
});

it('lets one tenant fail without stopping the rest', function (): void {
    // Both due, but tenant A's account row is pulled out from under the sweep
    // between the query and the write -- exactly the kind of failure a single
    // unguarded exception would otherwise have let stop the whole run.
    foreach ([$this->tenantA, $this->tenantB] as $tenant) {
        AiCreditAccount::query()->forTenant($tenant)->update([
            'monthly_allowance' => 200,
            'period_end' => now()->subDay(),
        ]);
    }

    DB::table('ai_credit_accounts')->where('tenant_id', $this->tenantA->getKey())->delete();

    $result = app(ResetCreditPeriodsService::class)->execute();

    expect($result['reset'])->toBe(1)
        ->and($this->ledger->accountFor($this->tenantB)->balance)->toBe(200);
});

it('runs the monthly reset from the console command', function (): void {
    AiCreditAccount::query()->forTenant($this->tenantA)->update([
        'monthly_allowance' => 150,
        'period_end' => now()->subDay(),
    ]);

    $this->artisan('ai:reset-monthly-credits')
        ->expectsOutputToContain('Checked')
        ->assertSuccessful();

    expect($this->ledger->accountFor($this->tenantA)->balance)->toBe(150);
});

// ------------------------------------------------------------- reconciliation

it('corrects a drifted balance and leaves an untouched one alone', function (): void {
    $this->ledger->grant($this->tenantA, 100, 'Plan allowance');
    AiCreditAccount::query()->forTenant($this->tenantA)->update(['balance' => 999]);

    $this->ledger->grant($this->tenantB, 50, 'Plan allowance');

    $result = app(ReconcileCreditsService::class)->execute();

    expect($result)->toBe(['checked' => 2, 'corrected' => 1])
        ->and($this->ledger->accountFor($this->tenantA)->balance)->toBe(100)
        ->and($this->ledger->accountFor($this->tenantB)->balance)->toBe(50);
});

it('audits a correction with no ledger entry created', function (): void {
    // Worth an audit trail precisely because it is unexpected: every write to
    // balance is meant to already go through the ledger.
    $this->ledger->grant($this->tenantA, 100, 'Plan allowance');
    AiCreditAccount::query()->forTenant($this->tenantA)->update(['balance' => 999]);

    $transactionsBefore = DB::table('ai_credit_transactions')->count();

    app(ReconcileCreditsService::class)->execute();

    $entry = AuditLog::query()->where('action', 'ai.credits_reconciled')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->tenant_id)->toBe($this->tenantA->getKey())
        ->and($entry->new_values['drift'])->toBe(899)
        ->and(DB::table('ai_credit_transactions')->count())->toBe($transactionsBefore);
});

it('does not audit a tenant with no drift', function (): void {
    $this->ledger->grant($this->tenantA, 100, 'Plan allowance');

    app(ReconcileCreditsService::class)->execute();

    expect(AuditLog::query()->where('action', 'ai.credits_reconciled')->exists())->toBeFalse();
});

it('lets one tenant is reconciliation failure stop only that tenant', function (): void {
    $this->ledger->grant($this->tenantA, 100, 'Plan allowance');
    AiCreditAccount::query()->forTenant($this->tenantA)->update(['balance' => 999]);

    $this->ledger->grant($this->tenantB, 50, 'Plan allowance');
    AiCreditAccount::query()->forTenant($this->tenantB)->update(['balance' => 777]);

    DB::table('ai_credit_accounts')->where('tenant_id', $this->tenantA->getKey())->delete();

    $result = app(ReconcileCreditsService::class)->execute();

    expect($result['corrected'])->toBe(1)
        ->and($this->ledger->accountFor($this->tenantB)->balance)->toBe(50);
});

it('runs the reconciliation from the console command', function (): void {
    $this->ledger->grant($this->tenantA, 100, 'Plan allowance');
    AiCreditAccount::query()->forTenant($this->tenantA)->update(['balance' => 250]);

    $this->artisan('ai:reconcile-credits')
        ->expectsOutputToContain('corrected 1')
        ->assertSuccessful();

    expect($this->ledger->accountFor($this->tenantA)->balance)->toBe(100);
});
