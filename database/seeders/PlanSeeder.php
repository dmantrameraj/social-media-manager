<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The reference plan tiers from docs/09-BILLING.md §3.
 *
 * Until this existed `DatabaseSeeder` created one test user and nothing else,
 * so `migrate:fresh --seed` produced a database with no plans at all: every
 * entitlement fell through to `config('entitlements.defaults')`, no
 * subscription could be created (`subscriptions.plan_id` is NOT NULL), and none
 * of the billing surface could be exercised on a fresh clone.
 *
 * **Insert-only. Existing rows are never modified.** §3 says these are "seed
 * values, not constants... Super Admin edits them without a deploy" — so a
 * seeder that overwrote on every deploy would silently revert a deliberate
 * price or limit change. Correcting a seeded value after the fact is a
 * migration or an admin edit, not a re-run of this.
 *
 * Written against `DB::table` rather than models because no Plan model exists:
 * the admin screens and `EntitlementResolver` both query these tables directly.
 */
final class PlanSeeder extends Seeder
{
    private const GB = 1024 ** 3;

    public function run(): void
    {
        foreach ($this->plans() as $slug => $plan) {
            $planId = $this->planId($slug, $plan);

            foreach ($plan['features'] as $key => $feature) {
                $this->feature($planId, (string) $key, $feature);
            }
        }
    }

    /**
     * Find or create the plan, and return its id.
     */
    private function planId(string $slug, array $plan): int
    {
        $existing = DB::table('plans')->where('slug', $slug)->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('plans')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'name' => $plan['name'],
            'slug' => $slug,
            'description' => $plan['description'],
            'is_public' => $plan['is_public'],
            'is_active' => true,
            'trial_days' => (int) config('billing.trial_days', 7),
            'sort_order' => $plan['sort_order'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array{type: EntitlementType, value?: int|bool}  $feature
     */
    private function feature(int $planId, string $key, array $feature): void
    {
        /*
         | Validated here because a seeder writing straight to the table bypasses
         | whatever validates an admin edit. A key that is not in the catalogue
         | produces a row nothing reads -- a limit that looks configured and is
         | silently unenforced, which is the failure this project has already
         | shipped once through a different route.
         */
        if (! array_key_exists($key, (array) config('entitlements.keys', []))) {
            throw new RuntimeException("Plan feature [{$key}] is not in the entitlement catalogue.");
        }

        $exists = DB::table('plan_features')
            ->where('plan_id', $planId)
            ->where('key', $key)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('plan_features')->insert([
            'plan_id' => $planId,
            'key' => $key,
            'value_type' => $feature['type']->value,
            // Unlimited stores null; booleans store 1/0 in the same column as
            // limits, per Entitlement::isEnabled().
            'value' => match (true) {
                $feature['type'] === EntitlementType::Unlimited => null,
                $feature['type'] === EntitlementType::Boolean => (int) ($feature['value'] ?? false),
                default => (int) ($feature['value'] ?? 0),
            },
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * docs/09-BILLING.md §3, verbatim.
     *
     * Keys the reference table does not mention -- customers.approval_workflow,
     * ai.autopilot, api.enabled, support.priority -- are deliberately absent, so
     * they resolve from `config('entitlements.defaults')`. Inventing tier values
     * for them here would put numbers nobody decided into the pricing page.
     *
     * NO PRICES ARE SEEDED. §3 specifies limits and says nothing about money,
     * and fabricating amounts would put invented commercial figures into a table
     * the checkout reads. `plan_prices` is filled in by whoever sets the pricing;
     * `subscriptions.plan_price_id` is nullable precisely so a manually-activated
     * tenant works without one.
     */
    private function plans(): array
    {
        return [
            'starter' => [
                'name' => 'Starter',
                'description' => 'For a solo operator or a small agency finding its feet.',
                'is_public' => true,
                'sort_order' => 10,
                'features' => [
                    'brands.max' => ['type' => EntitlementType::Limit, 'value' => 5],
                    'social_accounts.max' => ['type' => EntitlementType::Limit, 'value' => 10],
                    'team_members.max' => ['type' => EntitlementType::Limit, 'value' => 2],
                    'portal_users.max' => ['type' => EntitlementType::Limit, 'value' => 5],
                    'posts.scheduled_per_month' => ['type' => EntitlementType::Limit, 'value' => 100],
                    'ai.credits_per_month' => ['type' => EntitlementType::Limit, 'value' => 100],
                    'storage.max_bytes' => ['type' => EntitlementType::Limit, 'value' => 5 * self::GB],
                    'analytics.enabled' => ['type' => EntitlementType::Boolean, 'value' => false],
                    'white_label.enabled' => ['type' => EntitlementType::Boolean, 'value' => false],
                ],
            ],

            'professional' => [
                'name' => 'Professional',
                'description' => 'For an agency running a steady book of clients.',
                'is_public' => true,
                'sort_order' => 20,
                'features' => [
                    'brands.max' => ['type' => EntitlementType::Limit, 'value' => 15],
                    'social_accounts.max' => ['type' => EntitlementType::Limit, 'value' => 40],
                    'team_members.max' => ['type' => EntitlementType::Limit, 'value' => 5],
                    'portal_users.max' => ['type' => EntitlementType::Limit, 'value' => 20],
                    'posts.scheduled_per_month' => ['type' => EntitlementType::Limit, 'value' => 500],
                    'ai.credits_per_month' => ['type' => EntitlementType::Limit, 'value' => 500],
                    'storage.max_bytes' => ['type' => EntitlementType::Limit, 'value' => 25 * self::GB],
                    'analytics.enabled' => ['type' => EntitlementType::Boolean, 'value' => true],
                    'white_label.enabled' => ['type' => EntitlementType::Boolean, 'value' => false],
                ],
            ],

            'agency' => [
                'name' => 'Agency',
                'description' => 'For a larger team with white-label needs.',
                'is_public' => true,
                'sort_order' => 30,
                'features' => [
                    'brands.max' => ['type' => EntitlementType::Limit, 'value' => 25],
                    'social_accounts.max' => ['type' => EntitlementType::Limit, 'value' => 100],
                    'team_members.max' => ['type' => EntitlementType::Limit, 'value' => 10],
                    'portal_users.max' => ['type' => EntitlementType::Limit, 'value' => 50],
                    'posts.scheduled_per_month' => ['type' => EntitlementType::Limit, 'value' => 2000],
                    'ai.credits_per_month' => ['type' => EntitlementType::Limit, 'value' => 2000],
                    'storage.max_bytes' => ['type' => EntitlementType::Limit, 'value' => 100 * self::GB],
                    'analytics.enabled' => ['type' => EntitlementType::Boolean, 'value' => true],
                    'white_label.enabled' => ['type' => EntitlementType::Boolean, 'value' => true],
                ],
            ],

            /*
             | Not public: §3 marks its credits and storage "custom", which means
             | negotiated per deal. The migration's own comment says is_public
             | false "covers bespoke enterprise plans".
             |
             | The custom keys are seeded UNLIMITED rather than left out. Omitting
             | them would fall through to config defaults -- 1 GiB of storage --
             | leaving Enterprise strictly worse than Agency until somebody
             | noticed. A real deal narrows this with a per-tenant override, which
             | takes precedence over the plan.
             */
            'enterprise' => [
                'name' => 'Enterprise',
                'description' => 'Negotiated per agreement. Limits are set per tenant.',
                'is_public' => false,
                'sort_order' => 40,
                'features' => [
                    'brands.max' => ['type' => EntitlementType::Unlimited],
                    'social_accounts.max' => ['type' => EntitlementType::Unlimited],
                    'team_members.max' => ['type' => EntitlementType::Unlimited],
                    'portal_users.max' => ['type' => EntitlementType::Unlimited],
                    'posts.scheduled_per_month' => ['type' => EntitlementType::Unlimited],
                    'ai.credits_per_month' => ['type' => EntitlementType::Unlimited],
                    'storage.max_bytes' => ['type' => EntitlementType::Unlimited],
                    'analytics.enabled' => ['type' => EntitlementType::Boolean, 'value' => true],
                    'white_label.enabled' => ['type' => EntitlementType::Boolean, 'value' => true],
                ],
            ],
        ];
    }
}
