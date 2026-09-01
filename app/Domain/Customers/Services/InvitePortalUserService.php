<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates a client portal login and grants it access to specific brands.
 *
 * Portal users are tenant-scoped by design: the same person working with two
 * agencies gets two logins. See docs/03-TENANCY.md §8.
 */
final class InvitePortalUserService
{
    public function __construct(private readonly EntitlementResolver $entitlements) {}

    /**
     * @param  list<int>  $customerIds
     * @return array{user: CustomerPortalUser, password: string}
     */
    public function execute(
        Tenant $tenant,
        User $actor,
        string $name,
        string $email,
        array $customerIds,
        PortalRole $role = PortalRole::Approver,
    ): array {
        $email = Str::lower(trim($email));

        if ($customerIds === []) {
            // A portal login with no brand sees nothing at all, which reads as
            // a broken account rather than a restricted one.
            throw new RuntimeException('A client login must be granted at least one brand.');
        }

        $this->entitlements->guard($tenant, 'portal_users.max');

        $brands = $this->resolveBrands($tenant, $customerIds);

        // Not a real credential: the invitation email carries a single-use
        // set-password link, and this value is never sent anywhere. It exists
        // so the NOT NULL column holds something unguessable in the meantime.
        $placeholderPassword = Str::random(40);

        $portalUser = DB::transaction(function () use (
            $tenant, $actor, $name, $email, $brands, $role, $placeholderPassword
        ): CustomerPortalUser {
            $existing = CustomerPortalUser::query()
                ->forTenant($tenant)
                ->where('email', $email)
                ->first();

            if ($existing !== null) {
                throw new RuntimeException("{$email} already has a client login for this workspace.");
            }

            $portalUser = new CustomerPortalUser;
            $portalUser->tenant_id = $tenant->getKey();
            $portalUser->name = $name;
            $portalUser->email = $email;
            $portalUser->password = $placeholderPassword;
            $portalUser->invited_by_user_id = $actor->getKey();
            $portalUser->save();

            foreach ($brands as $brand) {
                $brand->portalUsers()->attach($portalUser->getKey(), ['role' => $role->value]);
            }

            $this->entitlements->forget($tenant, 'portal_users.max');

            return $portalUser;
        });

        return ['user' => $portalUser, 'password' => $placeholderPassword];
    }

    /**
     * Brand ids arriving from a request are proven to belong to this tenant
     * before any grant is written -- otherwise a client could be given access
     * to another agency's brand.
     *
     * @param  list<int>  $customerIds
     * @return Collection<int, Customer>
     */
    private function resolveBrands(Tenant $tenant, array $customerIds)
    {
        $ids = array_values(array_unique($customerIds));

        $brands = Customer::query()
            ->forTenant($tenant)
            ->whereIn('id', $ids)
            ->get();

        if ($brands->count() !== count($ids)) {
            throw new RuntimeException('One or more brands do not belong to this workspace.');
        }

        return $brands;
    }
}
