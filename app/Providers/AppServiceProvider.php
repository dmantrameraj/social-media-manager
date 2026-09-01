<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Listeners\RecordAuthenticationEvent;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->registerSocialProviders();
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
