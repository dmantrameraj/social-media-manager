<?php

declare(strict_types=1);

use App\Domain\Access\Services\SyncPermissionCatalogueService;
use App\Domain\AI\Models\AutopilotSetting;
use App\Domain\AI\Models\BrandBrain;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Models\MediaFolder;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\Models\SocialConnection;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Tenancy helpers
|--------------------------------------------------------------------------
*/

/**
 * Establish tenant context for the duration of a test, exactly as the
 * ResolveTenant middleware would.
 */
function actingForTenant(Tenant $tenant): Tenant
{
    app(TenantContext::class)->set($tenant);
    setPermissionsTeamId($tenant->getKey());

    return $tenant;
}

/** Clear tenant context, simulating a console or queue-bootstrap environment. */
function withoutTenantContext(): void
{
    app(TenantContext::class)->forget();
    setPermissionsTeamId(null);
}

/**
 * Project config/permissions.php into the database.
 *
 * RefreshDatabase truncates between tests, so any test touching roles or
 * permissions must call this first. Kept explicit rather than seeded globally,
 * so tests that do not need it do not pay for it.
 */
function seedPermissions(): void
{
    app(SyncPermissionCatalogueService::class)->execute();
}

/**
 * Provision a tenant with an owner, roles and a credit account, exactly as
 * signup or manual activation would.
 *
 * @return array{0: Tenant, 1: User}
 */
function provisionTenant(string $name = 'Test Agency'): array
{
    seedPermissions();

    $owner = User::factory()->create();

    $tenant = app(ProvisionTenantService::class)
        ->execute($owner, $name);

    return [$tenant, $owner->fresh()];
}

/**
 * Every model that carries a tenant_id column.
 *
 * The registry test asserts this list matches the schema, so a newly added
 * tenant-owned model cannot silently escape isolation coverage.
 *
 * @return array<class-string<Model>>
 */
function tenantOwnedModels(): array
{
    return [
        Customer::class,
        CustomerPortalUser::class,
        Media::class,
        MediaFolder::class,
        SocialConnection::class,
        SocialAccount::class,
        Post::class,
        PostTarget::class,
        BrandBrain::class,
        AutopilotSetting::class,
    ];
}

/**
 * Give a tenant an active subscription whose plan sets one entitlement.
 *
 * Written with the query builder rather than models because plans and
 * subscriptions have no Eloquent models yet -- those arrive in Step 9.
 */
function givePlanLimit(int $tenantId, string $key, int $value): void
{
    // A tenant holds at most one non-terminal subscription, so replace rather
    // than stack -- two active subscriptions is a state the app never produces.
    DB::table('subscriptions')->where('tenant_id', $tenantId)->delete();

    $planId = DB::table('plans')->insertGetId([
        'ulid' => (string) Str::ulid(),
        'name' => 'Limited',
        'slug' => 'limited-'.Str::lower(Str::random(6)),
        'is_public' => true, 'is_active' => true,
        'trial_days' => 7, 'sort_order' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('plan_features')->insert([
        'plan_id' => $planId, 'key' => $key,
        'value_type' => 'limit', 'value' => $value,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('subscriptions')->insert([
        'ulid' => (string) Str::ulid(),
        'tenant_id' => $tenantId, 'plan_id' => $planId,
        'status' => 'active', 'gateway' => 'manual', 'quantity' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}
