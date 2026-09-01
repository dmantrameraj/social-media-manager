<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\MediaFolder;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a Customer/Brand workspace.
 *
 * The entitlement check lives here, not in a controller: a limit enforced at
 * the HTTP layer is a limit the console, queue and future API paths all skip.
 */
final class CreateCustomerService
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Tenant $tenant, User $actor, array $attributes): Customer
    {
        $this->entitlements->guard($tenant, 'brands.max');

        return DB::transaction(function () use ($tenant, $actor, $attributes): Customer {
            $customer = new Customer;
            $customer->tenant_id = $tenant->getKey();

            // Explicit allow-list rather than fill($attributes): the caller may
            // hand us raw request data, and a blind fill turns an injected
            // tenant_id into a 500 instead of a no-op. Ownership fields are set
            // below, from the resolved tenant -- never from input.
            $customer->fill(Arr::only($attributes, [
                'name', 'legal_name', 'industry', 'website',
                'contact_name', 'contact_email', 'contact_phone', 'settings',
            ]));

            $customer->slug = $this->uniqueSlug(
                $tenant,
                (string) ($attributes['slug'] ?? $attributes['name'] ?? 'brand'),
            );

            // Brands inherit the agency's timezone unless told otherwise. It is
            // snapshotted rather than read through the relation, because
            // scheduling reads it on a hot path.
            $customer->timezone = (string) ($attributes['timezone'] ?? $tenant->timezone);
            $customer->status = CustomerStatus::Active;

            $customer->save();

            // The creator is assigned to the brand so they do not immediately
            // lose access to what they just made -- users without
            // customers.view_all see only assigned brands.
            $customer->users()->attach($actor->getKey());
            $actor->forgetAssignedCustomers();

            $this->seedSystemFolders($customer);

            // The brand count changed, so any cached brands.max verdict is now
            // stale. Invalidated explicitly rather than waiting for the TTL.
            $this->entitlements->forget($tenant, 'brands.max');

            return $customer;
        });
    }

    /**
     * Seeded media folders. Referenced elsewhere by system_key, which is why
     * the policy refuses to let them be renamed or deleted.
     */
    private function seedSystemFolders(Customer $customer): void
    {
        foreach ((array) config('media.system_folders', []) as $key => $name) {
            $folder = new MediaFolder;
            $folder->tenant_id = $customer->tenant_id;
            $folder->customer_id = $customer->getKey();
            $folder->name = (string) $name;
            $folder->system_key = (string) $key;
            $folder->save();
        }
    }

    /** Unique within the tenant -- the table's unique key is (tenant_id, slug). */
    private function uniqueSlug(Tenant $tenant, string $source): string
    {
        $base = Str::limit(Str::slug($source) ?: 'brand', 60, '');
        $candidate = $base;
        $attempt = 0;

        while ($this->slugTaken($tenant, $candidate)) {
            $attempt++;
            $candidate = $base.'-'.Str::lower(Str::random(6));

            if ($attempt > 10) {
                $candidate = $base.'-'.Str::lower(Str::ulid()->toString());
                break;
            }
        }

        return $candidate;
    }

    private function slugTaken(Tenant $tenant, string $slug): bool
    {
        return Customer::query()
            ->withTrashed()
            ->forTenant($tenant)
            ->where('slug', $slug)
            ->exists();
    }
}
