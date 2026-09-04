<?php

declare(strict_types=1);

use App\Domain\Analytics\Models\ReportShare;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\MonthlyReportNotification;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\Notification;

/*
 | Last month's report, sent to each client.
 |
 | The roadmap's last Phase 5 item. It exists because the report an agency
 | never remembers to send is the report the client never sees.
 */

beforeEach(function (): void {
    seedPermissions();
    Notification::fake();

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);
});

/** A client who should receive the report. */
function portalRecipient(?Customer $brand = null, string $role = 'approver'): CustomerPortalUser
{
    $brand ??= test()->brand;

    $user = CustomerPortalUser::factory()->create([
        'tenant_id' => $brand->tenant_id,
    ]);

    $user->customers()->attach($brand->getKey(), [
        'tenant_id' => $brand->tenant_id,
        'role' => $role,
    ]);

    return $user;
}

it('sends a link to the client', function (): void {
    $recipient = portalRecipient();

    $this->artisan('reports:send-monthly')->assertSuccessful();

    Notification::assertSentTo($recipient, MonthlyReportNotification::class);

    $share = ReportShare::query()->sole();

    expect($share->customer_id)->toBe($this->brand->getKey())
        ->and($share->isViewable())->toBeTrue();
});

it('reports on last month, not this one', function (): void {
    /*
     | Run on the 1st, "this month" is hours old and the report would be
     | empty. Naming the period explicitly also means a retried or late run
     | sends the same thing rather than a different one.
     */
    portalRecipient();

    $this->artisan('reports:send-monthly')->assertSuccessful();

    $share = ReportShare::query()->sole();
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();

    expect($share->window_from->format('Y-m'))->toBe($lastMonth->format('Y-m'))
        ->and($share->window_to->isBefore(now()->startOfMonth()))->toBeTrue();
});

it('mints no link when nobody would receive it', function (): void {
    /*
     | A share nobody was sent is an unauthenticated view of a client's data
     | created for no reason.
     */
    $this->artisan('reports:send-monthly')->assertSuccessful();

    expect(ReportShare::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('does not write to a suspended agency clients', function (): void {
    /*
     | Their client relationship is not ours to maintain while they are cut
     | off, and a report from a product the agency cannot log into invites a
     | confusing conversation.
     */
    portalRecipient();

    $this->tenant->forceFill(['status' => TenantStatus::Suspended->value])->save();

    $this->artisan('reports:send-monthly')->assertSuccessful();

    Notification::assertNothingSent();
    expect(ReportShare::query()->count())->toBe(0);
});

it('sends nothing on a dry run', function (): void {
    portalRecipient();

    $this->artisan('reports:send-monthly', ['--dry-run' => true])->assertSuccessful();

    Notification::assertNothingSent();
    expect(ReportShare::query()->count())->toBe(0);
});

it('keeps one agency report away from another', function (): void {
    $mine = portalRecipient();

    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);
    $rivalBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);
    $theirs = portalRecipient($rivalBrand);

    actingForTenant($this->tenant);

    $this->artisan('reports:send-monthly')->assertSuccessful();

    // Both are written to, each about their own brand only.
    Notification::assertSentTo($mine, MonthlyReportNotification::class);
    Notification::assertSentTo($theirs, MonthlyReportNotification::class);

    $shares = ReportShare::query()->acrossTenants()->get();

    expect($shares)->toHaveCount(2)
        ->and($shares->pluck('customer_id')->sort()->values()->all())
        ->toBe(collect([$this->brand->getKey(), $rivalBrand->getKey()])->sort()->values()->all());
});

it('writes to viewers as well as approvers', function (): void {
    // A viewer is still expected to look at what arrived -- the same rule
    // PostEventDispatcher already follows.
    $viewer = portalRecipient(role: 'viewer');

    $this->artisan('reports:send-monthly')->assertSuccessful();

    Notification::assertSentTo($viewer, MonthlyReportNotification::class);
});
