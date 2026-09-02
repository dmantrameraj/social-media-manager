<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\PermissionCatalogue;
use App\Domain\AI\Contracts\AiProviderInterface;
use App\Domain\AI\Providers\AnthropicProvider;
use App\Domain\AI\Providers\FakeAiProvider;
use App\Domain\Audit\Listeners\RecordAuthenticationEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         | SCOPED, not singleton: one instance per request/job, reset between
         | them. A plain singleton would leak one tenant's context into the
         | next request on the same worker under Octane.
         */
        $this->app->scoped(TenantContext::class);

        /*
         | A true singleton, not scoped: the provider map is registered once at
         | boot and is identical for every request. Resolving a fresh instance
         | per call would hand callers an empty registry.
         */
        $this->app->singleton(ProviderRegistry::class);
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configureDatabase();
        $this->configureAuthEvents();
        $this->registerPlatformGates();
        $this->registerSocialProviders();
        $this->registerAiProvider();
    }

    /**
     * Platform permissions resolve through gates, not through spatie roles.
     *
     * spatie's permissions are team-scoped to a tenant, so a `platform.*`
     * permission held that way would be a per-agency grant -- exactly backwards
     * for authority that spans every agency. These are answered by
     * is_super_admin instead, which no tenant can assign.
     *
     * Note there is deliberately NO Gate::before granting Super Admins every
     * ability. Passing tenant policies automatically would mean a support
     * engineer silently satisfies checks written to protect an agency's data.
     * The /admin surface is authorised by EnsureSuperAdmin and these gates;
     * reaching agency data is what impersonation is for, and that is audited.
     */
    private function registerPlatformGates(): void
    {
        foreach ($this->app->make(PermissionCatalogue::class)->platformPermissions() as $permission) {
            Gate::define(
                $permission,
                static fn (mixed $user): bool => $user instanceof User && $user->isSuperAdmin(),
            );
        }
    }

    /**
     * Real provider adapters are registered here as each one ships.
     *
     * FakeProvider is registered outside production only: it exists so the
     * publishing engine's hardest behaviour -- claim locking, retry
     * classification, idempotent recovery -- can be proven without a live API
     * or platform review.
     */
    private function registerSocialProviders(): void
    {
        $registry = $this->app->make(ProviderRegistry::class);

        if (! $this->app->isProduction()) {
            $registry->register('fake', FakeProvider::class);
        }
    }

    /**
     * Bind the configured AI provider.
     *
     * Features depend on AiProviderInterface, never on a concrete vendor, so
     * swapping providers is a config change.
     */
    private function registerAiProvider(): void
    {
        $this->app->bind(AiProviderInterface::class, function (): AiProviderInterface {
            $key = (string) config('ai.default', 'anthropic');

            return match ($key) {
                'fake' => new FakeAiProvider,
                default => new AnthropicProvider,
            };
        });
    }

    /**
     * Authentication events are recorded synchronously. A security log that
     * arrives late -- or not at all, because a queue worker died -- is not a
     * security log.
     */
    private function configureAuthEvents(): void
    {
        Event::subscribe(RecordAuthenticationEvent::class);
    }

    private function configureModels(): void
    {
        /*
         | Fail loudly on a missing relationship rather than silently issuing
         | N+1 queries. Disabled in production so a missed eager-load degrades
         | performance instead of throwing at a customer.
         */
        Model::preventLazyLoading(! $this->app->isProduction());

        /*
         | Throw when code assigns an attribute that is not fillable, instead
         | of discarding it silently. Silent discard is how a guarded column
         | like is_super_admin appears to be set but is not -- or worse, how a
         | genuine bug hides for months.
         */
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Model::unguard(false);
    }

    private function configureDatabase(): void
    {
        // Surface slow queries in development so index regressions are caught
        // while the dataset is still small enough to hide them.
        if ($this->app->isProduction()) {
            return;
        }

        DB::whenQueryingForLongerThan(500, function (): void {
            // Wired to the logger in Phase 1 Step 11 alongside audit logging.
        });
    }
}
