<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantUser;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\Storage;

/*
 | SubscriptionLifecycleService has been stamping tenants.purge_after on
 | cancellation since billing shipped, and Tenant::duePurge() was ready to find
 | them. Nothing consumed either, so the 60-day retention promise in
 | docs/10-SECURITY.md §9 was a date written into a column and never acted on.
 */

beforeEach(function (): void {
    seedPermissions();
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Closed Agency');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
});

/** Mark the tenant's retention clock as expired. */
function markDue(Tenant $tenant): Tenant
{
    $tenant->purge_after = now()->subDay();
    $tenant->save();

    return $tenant;
}

// ----------------------------------------------------------------- selection

it('purges nothing while the clock is still running', function (): void {
    $this->tenant->purge_after = now()->addDays(30);
    $this->tenant->save();

    $this->artisan('platform:purge-expired-data')
        ->expectsOutputToContain('Nothing is due for purge.')
        ->assertSuccessful();

    expect($this->tenant->fresh()->purged_at)->toBeNull();
});

it('changes nothing on a dry run', function (): void {
    // The least reversible thing the application does, so it must be possible
    // to see the list without trusting that you read the dates correctly.
    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data --dry-run')->assertSuccessful();

    expect($this->tenant->fresh()->purged_at)->toBeNull()
        ->and($this->tenant->fresh()->purge_after)->not->toBeNull();
});

// --------------------------------------------------------------------- media

it('deletes media bytes, variants included', function (): void {
    /*
     | Variants are separate files on the same disk. A purge that left
     | thumbnails behind would leave a legible copy of every image it deleted.
     */
    $media = Media::factory()->forCustomer($this->brand)->create([
        'path' => 'media/original.jpg',
        'thumbnail_path' => 'media/variants/original-thumb.webp',
        'variants' => [
            'thumb' => 'media/variants/original-thumb.webp',
            'preview' => 'media/variants/original-preview.webp',
        ],
    ]);

    foreach (['media/original.jpg', 'media/variants/original-thumb.webp', 'media/variants/original-preview.webp'] as $path) {
        Storage::disk('local')->put($path, 'bytes');
    }

    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    Storage::disk('local')->assertMissing('media/original.jpg');
    Storage::disk('local')->assertMissing('media/variants/original-thumb.webp');
    Storage::disk('local')->assertMissing('media/variants/original-preview.webp');

    expect(Media::query()->acrossTenants()->withTrashed()->find($media->getKey()))->toBeNull();
});

it('deletes media that was only soft deleted', function (): void {
    // A soft-deleted row still points at bytes on disk, which is precisely
    // what a purge is supposed to remove.
    $media = Media::factory()->forCustomer($this->brand)->create(['path' => 'media/gone.jpg']);
    Storage::disk('local')->put('media/gone.jpg', 'bytes');
    $media->delete();

    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    Storage::disk('local')->assertMissing('media/gone.jpg');
});

// --------------------------------------------------------------- oauth grants

it('force deletes social connections rather than soft deleting them', function (): void {
    // A soft-deleted connection still holds the encrypted tokens.
    $connection = SocialConnection::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $this->brand->getKey(),
    ]);

    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    expect(SocialConnection::query()->acrossTenants()->withTrashed()->find($connection->getKey()))
        ->toBeNull();
});

// -------------------------------------------------------------- anonymisation

it('anonymises a user who belonged only to this workspace', function (): void {
    markDue($this->tenant);

    $originalEmail = $this->owner->email;

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    $fresh = $this->owner->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->email)->not->toBe($originalEmail)
        ->and($fresh->email)->toEndWith('@purged.invalid')
        ->and($fresh->name)->toBe('Deleted user');
});

it('leaves a user who works for another agency alone', function (): void {
    /*
     | A freelancer with three agencies has one login. Anonymising them because
     | one of those agencies cancelled would destroy their access to the other
     | two -- deleting a person on the say-so of somebody who is not them.
     */
    $freelancer = memberWithRole($this->tenant, 'Designer');

    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Still Trading');

    $freelancer->tenants()->attach($otherTenant->getKey(), [
        'status' => MembershipStatus::Active->value,
        'joined_at' => now(),
    ]);

    $email = $freelancer->email;

    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    expect($freelancer->fresh()->email)->toBe($email);
});

it('removes the membership even for a user it keeps', function (): void {
    $freelancer = memberWithRole($this->tenant, 'Designer');

    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Still Trading');
    $freelancer->tenants()->attach($otherTenant->getKey(), [
        'status' => MembershipStatus::Active->value,
        'joined_at' => now(),
    ]);

    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    expect(TenantUser::query()->where('tenant_id', $this->tenant->getKey())->count())->toBe(0);
});

it('anonymises portal users', function (): void {
    // Brands hang off a pivot, not a column on the user.
    $portalUser = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
    ]);

    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    expect($portalUser->fresh()->email)->toEndWith('@purged.invalid');
});

it('keeps the row so history stays attributable', function (): void {
    /*
     | Audit entries, post authorship and approval records point at these rows.
     | Deleting them would either cascade the history away or leave it
     | dangling; anonymising keeps it intact and truthful.
     */
    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    expect(User::query()->whereKey($this->owner->getKey())->exists())->toBeTrue();
});

// --------------------------------------------------------------- book-keeping

it('records when the purge happened', function (): void {
    // purge_after alone cannot tell a tenant whose data was destroyed from a
    // cancelled one that was never due.
    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    $fresh = $this->tenant->fresh();

    expect($fresh->purged_at)->not->toBeNull()
        ->and($fresh->purge_after)->toBeNull();
});

it('does not purge the same tenant twice', function (): void {
    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();
    $this->artisan('platform:purge-expired-data')
        ->expectsOutputToContain('Nothing is due for purge.')
        ->assertSuccessful();

    expect(AuditLog::query()->where('action', 'tenancy.data_purged')->count())->toBe(1);
});

it('audits the purge with counts and no names', function (): void {
    // Naming what was deleted would put the data back into the log that the
    // purge just removed from the tables.
    $email = $this->owner->email;

    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    $entry = AuditLog::query()->where('action', 'tenancy.data_purged')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->new_values)->toHaveKeys(['connections', 'media', 'users', 'portal_users'])
        ->and(json_encode($entry->new_values))->not->toContain($email);
});

it('purges one tenant on request without waiting for its clock', function (): void {
    // So an erasure request can be honoured immediately rather than waiting
    // out the retention period.
    expect($this->tenant->purge_after)->toBeNull();

    $this->artisan('platform:purge-expired-data --tenant='.$this->tenant->getKey())
        ->assertSuccessful();

    expect($this->tenant->fresh()->purged_at)->not->toBeNull();
});

it('leaves other tenants untouched', function (): void {
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Still Trading');
    $email = $otherOwner->email;

    markDue($this->tenant);

    $this->artisan('platform:purge-expired-data')->assertSuccessful();

    expect($otherTenant->fresh()->purged_at)->toBeNull()
        ->and($otherOwner->fresh()->email)->toBe($email);
});
