<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Models\Customer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Brand updates and lifecycle transitions.
 *
 * Archive rather than delete is the normal path: a brand carries content,
 * media and (later) social connections, and an agency that loses a client
 * usually wants the history retained.
 */
final class UpdateCustomerService
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Customer $customer, array $attributes): Customer
    {
        // Explicit allow-list: slug, status and tenant_id are lifecycle-owned
        // and must never be reachable from caller-supplied input.
        $customer->fill(Arr::only($attributes, [
            'name', 'legal_name', 'industry', 'website', 'timezone',
            'contact_name', 'contact_email', 'contact_phone', 'settings',
        ]));

        $customer->save();

        return $customer;
    }

    /**
     * Archiving frees the brand's slot against brands.max, so the entitlement
     * cache must be dropped -- otherwise an agency at its limit still cannot
     * create a replacement.
     */
    public function archive(Customer $customer): Customer
    {
        return DB::transaction(function () use ($customer): Customer {
            $customer->status = CustomerStatus::Archived;
            $customer->save();

            $this->entitlements->forget($customer->tenant, 'brands.max');

            return $customer;
        });
    }

    /**
     * Restoring consumes a slot again, so the limit is re-checked. An agency
     * that downgraded while a brand was archived must not get it back for free.
     */
    public function unarchive(Customer $customer): Customer
    {
        return DB::transaction(function () use ($customer): Customer {
            $this->entitlements->guard($customer->tenant, 'brands.max');

            $customer->status = CustomerStatus::Active;
            $customer->save();

            $this->entitlements->forget($customer->tenant, 'brands.max');

            return $customer;
        });
    }
}
