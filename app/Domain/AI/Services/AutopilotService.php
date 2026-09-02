<?php

declare(strict_types=1);

namespace App\Domain\AI\Services;

use App\Domain\AI\Features\CaptionFeature;
use App\Domain\AI\Features\IdeasFeature;
use App\Domain\AI\Models\AutopilotSetting;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Tenancy\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generates draft content on a cadence.
 *
 * THE CONSTRAINT THAT MATTERS: autopilot creates posts at DRAFT and nothing
 * else. It has no path to SCHEDULED, and it does not touch approval state.
 * Everything it produces traverses the same PostStatusMachine and the same
 * client-approval gate as human-authored content.
 *
 * That is deliberate and load-bearing. An agency's client did not agree to let
 * a model post on their behalf unreviewed, and a feature that quietly bypassed
 * approval would be the fastest way to lose an account.
 *
 * See docs/08-AI-ARCHITECTURE.md §8.
 */
final class AutopilotService
{
    public function __construct(
        private readonly GenerateContentService $generator,
        private readonly EntitlementResolver $entitlements,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{brands: int, drafts: int, skipped: int}
     */
    public function run(): array
    {
        $brands = 0;
        $drafts = 0;
        $skipped = 0;

        AutopilotSetting::query()
            ->acrossTenants()
            ->due()
            ->orderBy('id')
            ->chunkById(50, function ($settings) use (&$brands, &$drafts, &$skipped): void {
                foreach ($settings as $setting) {
                    $brands++;

                    $created = $this->runForSetting($setting);

                    if ($created === null) {
                        $skipped++;

                        continue;
                    }

                    $drafts += $created;
                }
            });

        return ['brands' => $brands, 'drafts' => $drafts, 'skipped' => $skipped];
    }

    /**
     * @return int|null null when the brand was skipped
     */
    public function runForSetting(AutopilotSetting $setting): ?int
    {
        $tenant = Tenant::query()->find($setting->tenant_id);

        if ($tenant === null) {
            return null;
        }

        // Run inside the brand's own tenant, so the generator's own
        // cross-tenant guard sees the context it expects.
        return $this->context->run($tenant, function () use ($setting, $tenant): ?int {
            $customer = Customer::query()->find($setting->customer_id);

            if ($customer === null || ! $customer->status->countsTowardLimit()) {
                return null;
            }

            // A suspended tenant does not get free generation.
            if (! $tenant->permitsProductAccess()) {
                return null;
            }

            if (! $this->entitlements->value($tenant, 'ai.autopilot')->isEnabled()) {
                return null;
            }

            $actor = $this->actorFor($tenant);

            if ($actor === null) {
                return null;
            }

            try {
                $ideas = $this->generator->execute(
                    new IdeasFeature,
                    $customer,
                    $actor,
                    ['count' => 3, 'theme' => $this->themeFor($setting)],
                );
            } catch (Throwable) {
                // Deliberately broad. Running dry on credits, losing the
                // entitlement, or the provider failing are all the same thing
                // here: this brand does not get content this run, and the
                // sweep continues. One broken brand must not halt every other.
                //
                // The clock still advances, so a persistently failing brand
                // cannot monopolise every run.
                $setting->scheduleNextRun();

                return null;
            }

            $created = $this->createDrafts($setting, $customer, $actor, $ideas['ideas'] ?? []);

            $setting->scheduleNextRun();

            return $created;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $ideas
     */
    private function createDrafts(
        AutopilotSetting $setting,
        Customer $customer,
        User $actor,
        array $ideas,
    ): int {
        $created = 0;

        foreach (array_slice($ideas, 0, 1) as $idea) {
            $hook = trim((string) ($idea['hook'] ?? ''));

            if ($hook === '') {
                continue;
            }

            try {
                $caption = $this->generator->execute(
                    new CaptionFeature,
                    $customer,
                    $actor,
                    ['topic' => $hook],
                );
            } catch (Throwable) {
                continue;
            }

            DB::transaction(function () use ($customer, $actor, $hook, $caption, &$created): void {
                $post = new Post;
                $post->tenant_id = $customer->tenant_id;
                $post->customer_id = $customer->getKey();
                $post->created_by_user_id = $actor->getKey();
                $post->title = Str::limit($hook, 180, '');
                $post->body = (string) ($caption['caption'] ?? '');
                $post->content_type = 'text';

                // DRAFT, always. Autopilot has no path to SCHEDULED and does
                // not set approval state -- the workflow decides that, exactly
                // as it does for a human author.
                $post->status = PostStatus::Draft;

                // Provenance is never ambiguous: an agency must be able to
                // tell at a glance which posts a model wrote.
                $post->source = 'ai';
                $post->approval_required = $customer->requiresClientApproval();
                $post->timezone = $customer->effectiveTimezone();
                $post->save();

                $this->audit->log(
                    action: 'post.autopilot_created',
                    auditable: $post,
                    newValues: ['status' => PostStatus::Draft->value, 'source' => 'ai'],
                    actor: $actor,
                );

                $created++;
            });
        }

        return $created;
    }

    /**
     * Rotate through the configured themes so a brand does not get the same
     * angle every run.
     */
    private function themeFor(AutopilotSetting $setting): string
    {
        $themes = [];

        foreach ((array) ($setting->themes ?? []) as $theme) {
            $theme = trim((string) $theme);

            if ($theme !== '') {
                $themes[] = $theme;
            }
        }

        if ($themes === []) {
            return '';
        }

        return $themes[array_rand($themes)];
    }

    /**
     * Autopilot acts as the tenant owner, so generated content is attributable
     * to a real accountable person rather than to nobody.
     */
    private function actorFor(Tenant $tenant): ?User
    {
        if ($tenant->owner_user_id !== null) {
            return User::query()->find($tenant->owner_user_id);
        }

        return $tenant->users()->first();
    }
}
