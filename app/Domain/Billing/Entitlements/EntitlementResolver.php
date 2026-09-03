<?php

declare(strict_types=1);

namespace App\Domain\Billing\Entitlements;

use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use App\Domain\Billing\Entitlements\Exceptions\EntitlementExceeded;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resolves a limit for a tenant.
 *
 * Precedence, per docs/09-BILLING.md §3:
 *
 *     subscription_overrides  ->  plan_features  ->  config default
 *
 * An override lets Super Admin raise one tenant's ceiling without a plan
 * migration, which is the whole point: "plan says 10, this customer gets 100"
 * must not require inventing a bespoke plan.
 */
final class EntitlementResolver
{
    public function value(Tenant $tenant, string $key): Entitlement
    {
        $this->assertKnownKey($key);

        if (! config('entitlements.cache.enabled', true)) {
            return $this->resolve($tenant, $key);
        }

        $cacheKey = $this->cacheKey($tenant, $key);

        /*
         | Only scalars cross the cache boundary -- see Entitlement::toCacheArray().
         | Caching the object itself writes cleanly and then reads back as
         | __PHP_Incomplete_Class, because `cache.serializable_classes` is false
         | by default in Laravel 13. That fatals here at the return type, and it
         | does so on every cache store except `array`, which is what the test
         | suite uses -- so it is invisible to tests and total in production.
         */
        $cached = Entitlement::fromCacheArray(Cache::get($cacheKey));

        if ($cached instanceof Entitlement) {
            return $cached;
        }

        $entitlement = $this->resolve($tenant, $key);

        Cache::put(
            $cacheKey,
            $entitlement->toCacheArray(),
            (int) config('entitlements.cache.ttl', 3600),
        );

        return $entitlement;
    }

    public function allows(Tenant $tenant, string $key, int $requested = 1): bool
    {
        $entitlement = $this->value($tenant, $key);

        return $entitlement->permits($this->currentUsage($tenant, $key), $requested);
    }

    /**
     * Enforcement point. Called by services at the moment of creation, never
     * by controllers -- a limit checked in a controller is a limit the API and
     * console paths skip.
     *
     * @throws EntitlementExceeded
     */
    public function guard(Tenant $tenant, string $key, int $requested = 1): void
    {
        $entitlement = $this->value($tenant, $key);
        $usage = $this->currentUsage($tenant, $key);

        if (! $entitlement->permits($usage, $requested)) {
            throw new EntitlementExceeded($entitlement, $usage);
        }
    }

    private function resolve(Tenant $tenant, string $key): Entitlement
    {
        // 1. Tenant-specific override, ignoring expired ones.
        $override = DB::table('subscription_overrides')
            ->where('tenant_id', $tenant->getKey())
            ->where('key', $key)
            ->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($override !== null) {
            return new Entitlement(
                $key,
                EntitlementType::from($override->value_type),
                $override->value === null ? null : (int) $override->value,
                'override',
            );
        }

        // 2. The active subscription's plan.
        $feature = DB::table('subscriptions')
            ->join('plan_features', 'plan_features.plan_id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.tenant_id', $tenant->getKey())
            ->whereNull('subscriptions.deleted_at')
            ->whereIn('subscriptions.status', $this->entitlingStatuses())
            ->where('plan_features.key', $key)
            ->select('plan_features.value_type', 'plan_features.value')
            // A tenant is meant to hold at most one non-terminal subscription,
            // but that invariant is enforced in the service layer and could be
            // violated by a bad migration or a manual fix. Ordering makes the
            // outcome deterministic rather than dependent on row order: the
            // most recent subscription wins.
            ->orderByDesc('subscriptions.id')
            ->first();

        if ($feature !== null) {
            return new Entitlement(
                $key,
                EntitlementType::from($feature->value_type),
                $feature->value === null ? null : (int) $feature->value,
                'plan',
            );
        }

        // 3. System default.
        $definition = $this->definition($key);
        $type = $definition['type'] ?? EntitlementType::Limit;

        return new Entitlement(
            $key,
            $type instanceof EntitlementType ? $type : EntitlementType::from((string) $type),
            $definition['default'] === null ? null : (int) $definition['default'],
            'default',
        );
    }

    /**
     * Statuses that still grant plan entitlements. past_due and grace do:
     * revoking a paying customer's product the moment a card expires is what
     * turns a recoverable billing failure into a cancellation.
     *
     * @return list<string>
     */
    private function entitlingStatuses(): array
    {
        return ['trialing', 'active', 'past_due', 'grace'];
    }

    /**
     * Current consumption for a countable entitlement.
     *
     * Counts live rather than caching, because a stale count either blocks a
     * paying customer or silently gives product away.
     */
    public function currentUsage(Tenant $tenant, string $key): int
    {
        $usage = $this->definition($key)['usage'] ?? null;

        return match ($usage) {
            'brands' => DB::table('customers')
                ->where('tenant_id', $tenant->getKey())
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->count(),

            'team_members' => DB::table('tenant_user')
                ->where('tenant_id', $tenant->getKey())
                ->where('status', 'active')
                ->count(),

            'portal_users' => DB::table('customer_portal_users')
                ->where('tenant_id', $tenant->getKey())
                ->whereNull('deleted_at')
                ->count(),

            'social_accounts' => 0,   // Phase 2
            'posts_scheduled_this_period' => 0, // Phase 3

            'storage_bytes' => (int) DB::table('media')
                ->where('tenant_id', $tenant->getKey())
                ->whereNull('deleted_at')
                ->whereIn('status', ['ready', 'processing'])
                // Variants are real files on the same disk. Counting only
                // the upload would let a tenant at their limit keep writing
                // derivatives the quota never sees.
                ->sum(DB::raw('size_bytes + variant_bytes')),

            'ai_credits_consumed_this_period' => 0, // Phase 4

            default => 0,
        };
    }

    public function forget(Tenant $tenant, ?string $key = null): void
    {
        if ($key !== null) {
            Cache::forget($this->cacheKey($tenant, $key));

            return;
        }

        foreach (array_keys((array) config('entitlements.keys', [])) as $known) {
            Cache::forget($this->cacheKey($tenant, (string) $known));
        }
    }

    private function cacheKey(Tenant $tenant, string $key): string
    {
        return sprintf(
            '%s:%d:%s',
            (string) config('entitlements.cache.prefix', 'entitlements'),
            $tenant->getKey(),
            $key,
        );
    }

    /**
     * A typo must not silently resolve to "no limit". Keys are a closed set.
     */
    private function assertKnownKey(string $key): void
    {
        if (! array_key_exists($key, (array) config('entitlements.keys', []))) {
            throw new InvalidArgumentException("Unknown entitlement key [{$key}].");
        }
    }

    /**
     * Look a definition up by literal key.
     *
     * NOT config("entitlements.keys.{$key}"): entitlement keys contain dots
     * ('brands.max'), which Laravel's config helper reads as nested array
     * traversal. That silently returns null, which previously made every usage
     * counter fall through to zero and disabled limit enforcement entirely.
     *
     * @return array<string, mixed>
     */
    private function definition(string $key): array
    {
        return (array) (((array) config('entitlements.keys', []))[$key] ?? []);
    }
}
