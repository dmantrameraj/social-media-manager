<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\TenantPurgeWarningNotification;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\Notification;

/*
 | docs/10-SECURITY.md §9 has always said the purge is "preceded by warning
 | emails at 30 and 7 days", and config('tenancy.purge_warning_days') has held
 | [30, 7] since Phase 0. Nothing read it, so the purge shipped able to delete
 | an agency's entire history with no notice.
 */

beforeEach(function (): void {
    seedPermissions();
    Notification::fake();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Leaving Agency');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);
});

/** Put the purge deadline $days away. */
function dueIn(Tenant $tenant, int $days): Tenant
{
    $tenant->purge_after = now()->addDays($days);
    $tenant->save();

    return $tenant->fresh();
}

// ----------------------------------------------------------------- scheduling

it('says nothing while the deadline is far off', function (): void {
    dueIn($this->tenant, 45);

    $this->artisan('platform:warn-pending-purge')->assertSuccessful();

    Notification::assertNothingSent();
});

it('warns at the thirty day mark', function (): void {
    dueIn($this->tenant, 30);

    $this->artisan('platform:warn-pending-purge')->assertSuccessful();

    Notification::assertSentTo($this->owner, TenantPurgeWarningNotification::class);
});

it('warns again at the seven day mark', function (): void {
    dueIn($this->tenant, 30);
    $this->artisan('platform:warn-pending-purge');

    dueIn($this->tenant, 7);
    $this->artisan('platform:warn-pending-purge');

    Notification::assertSentToTimes($this->owner, TenantPurgeWarningNotification::class, 2);
});

it('does not repeat a warning it has already sent', function (): void {
    // The command runs daily, and the deadline sits inside the window for
    // weeks. Without this it would mail every morning.
    dueIn($this->tenant, 20);

    $this->artisan('platform:warn-pending-purge');
    $this->artisan('platform:warn-pending-purge');
    $this->artisan('platform:warn-pending-purge');

    Notification::assertSentToTimes($this->owner, TenantPurgeWarningNotification::class, 1);
});

it('sends one message when several stages are crossed at once', function (): void {
    /*
     | If the job does not run for a month, a tenant crosses 30 and 7 between
     | two runs. Two emails would put contradictory deadlines in one inbox on
     | one morning, and the 30-day one would state a date already past.
     */
    dueIn($this->tenant, 5);

    $this->artisan('platform:warn-pending-purge');

    Notification::assertSentToTimes($this->owner, TenantPurgeWarningNotification::class, 1);

    // Both stages recorded, so the skipped one never fires later.
    // Ints, not strings: PHP casts a numeric array key to an integer whatever
    // the JSON said.
    expect(array_keys((array) $this->tenant->fresh()->purge_warnings_sent))
        ->toEqualCanonicalizing([30, 7]);
});

it('quotes the real days remaining, not the stage it fired on', function (): void {
    // The stages decide when to speak, not what to say. A message claiming
    // "30 days" when five remain is worse than no message.
    dueIn($this->tenant, 5);

    $this->artisan('platform:warn-pending-purge');

    Notification::assertSentTo(
        $this->owner,
        TenantPurgeWarningNotification::class,
        fn (TenantPurgeWarningNotification $n): bool => $n->daysRemaining === 5,
    );
});

// -------------------------------------------------------------------- content

it('names the deadline in the subject', function (): void {
    dueIn($this->tenant, 7);

    $this->artisan('platform:warn-pending-purge');

    Notification::assertSentTo(
        $this->owner,
        TenantPurgeWarningNotification::class,
        function (TenantPurgeWarningNotification $n): bool {
            $mail = $n->toMail($this->owner);

            return str_contains($mail->subject ?? '', 'will be deleted');
        },
    );
});

it('reaches mail and the in-app list, and ignores notification preferences', function (): void {
    /*
     | Every other notification here can be switched off. This one must not be:
     | an unsubscribe made months ago for post updates must not silently
     | suppress "your data is deleted in seven days".
     */
    dueIn($this->tenant, 7);

    $this->artisan('platform:warn-pending-purge');

    Notification::assertSentTo(
        $this->owner,
        TenantPurgeWarningNotification::class,
        fn (TenantPurgeWarningNotification $n, array $channels): bool => in_array('mail', $channels, true) && in_array('database', $channels, true),
    );
});

// ---------------------------------------------------------------- other cases

it('says nothing once the tenant has already been purged', function (): void {
    dueIn($this->tenant, 7);
    $this->tenant->purged_at = now();
    $this->tenant->save();

    $this->artisan('platform:warn-pending-purge')->assertSuccessful();

    Notification::assertNothingSent();
});

it('says nothing for a tenant with no deadline at all', function (): void {
    $this->artisan('platform:warn-pending-purge')
        ->expectsOutputToContain('No tenants are awaiting purge.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

it('does not mark a warning sent when there is no owner to receive it', function (): void {
    // A restored owner must still get their warning rather than finding the
    // stage already ticked off.
    $tenant = dueIn($this->tenant, 7);
    $tenant->owner_user_id = null;
    $tenant->save();

    $this->artisan('platform:warn-pending-purge')->assertSuccessful();

    Notification::assertNothingSent();

    expect($tenant->fresh()->purge_warnings_sent)->toBeNull();
});

it('sends nothing on a dry run', function (): void {
    dueIn($this->tenant, 7);

    $this->artisan('platform:warn-pending-purge --dry-run')->assertSuccessful();

    Notification::assertNothingSent();

    expect($this->tenant->fresh()->purge_warnings_sent)->toBeNull();
});

it('audits the warning', function (): void {
    dueIn($this->tenant, 7);

    $this->artisan('platform:warn-pending-purge');

    $entry = AuditLog::query()->where('action', 'tenancy.purge_warning_sent')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_values)->toHaveKey('days_remaining');
});
