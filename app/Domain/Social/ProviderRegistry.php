<?php

declare(strict_types=1);

namespace App\Domain\Social;

use App\Domain\Social\Contracts\SocialProviderInterface;
use App\Domain\Social\DTO\CapabilitySet;
use App\Domain\Social\Enums\SocialAccountType;
use App\Domain\Social\Exceptions\UnknownProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves provider adapters by key.
 *
 * The publishing engine goes through this rather than referencing concrete
 * providers, which is what lets a new network be added without touching the
 * publisher. Registration is explicit so an unregistered key fails loudly
 * rather than resolving to something unexpected.
 */
final class ProviderRegistry
{
    /** @var array<string, class-string<SocialProviderInterface>> */
    private array $providers = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<SocialProviderInterface>  $class */
    public function register(string $key, string $class): void
    {
        $this->providers[$key] = $class;
    }

    public function for(string $key): SocialProviderInterface
    {
        if (! isset($this->providers[$key])) {
            throw new UnknownProvider($key);
        }

        return $this->container->make($this->providers[$key]);
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * Registered AND enabled in config. A provider can be built and registered
     * but withheld -- X ships disabled because its write quota is a commercial
     * question, not a technical one.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            array_keys($this->providers),
            static fn (string $key): bool => (bool) config("social.providers.{$key}.enabled", false),
        ));
    }

    /** @return list<string> */
    public function registered(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Base capabilities for a provider and account type, before narrowing by
     * the scopes actually granted.
     */
    public function baseCapabilities(string $providerKey, SocialAccountType $type): CapabilitySet
    {
        $config = (array) config("social.providers.{$providerKey}.{$type->value}", []);

        return new CapabilitySet(
            features: (array) ($config['capabilities'] ?? []),
            limits: (array) ($config['limits'] ?? []),
        );
    }

    /**
     * Capabilities narrowed by granted scopes.
     *
     * A Page connected without pages_manage_posts is not publish-capable no
     * matter what config says: users can decline individual scopes, and
     * assuming otherwise produces publish failures that look like bugs.
     *
     * @param  list<string>  $grantedScopes
     */
    public function resolveCapabilities(
        string $providerKey,
        SocialAccountType $type,
        array $grantedScopes,
    ): CapabilitySet {
        $config = (array) config("social.providers.{$providerKey}.{$type->value}", []);

        $set = new CapabilitySet(
            features: (array) ($config['capabilities'] ?? []),
            limits: (array) ($config['limits'] ?? []),
            grantedScopes: $grantedScopes,
        );

        // Missing a required scope disables publishing entirely, not just one
        // feature -- there is nothing useful the account can do.
        $required = (array) ($config['required_scopes'] ?? []);

        foreach ($required as $scope) {
            if (! $set->hasScope($scope)) {
                return new CapabilitySet(
                    features: array_map(static fn (): bool => false, $set->features),
                    limits: $set->limits,
                    grantedScopes: $grantedScopes,
                );
            }
        }

        return $set->narrowedByScopes((array) ($config['feature_scopes'] ?? []));
    }
}
