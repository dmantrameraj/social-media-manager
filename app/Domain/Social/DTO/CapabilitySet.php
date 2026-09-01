<?php

declare(strict_types=1);

namespace App\Domain\Social\DTO;

/**
 * What one social account can actually do.
 *
 * Resolved from config/social.php for the account TYPE, then narrowed by the
 * scopes the provider actually granted. A Facebook Page connected without
 * pages_manage_posts is not publish-capable no matter what the config says --
 * users can decline individual scopes, and assuming otherwise produces publish
 * failures that look like bugs.
 *
 * The composer reads this to decide which options to render, so an unsupported
 * combination is never offered rather than being rejected after the fact.
 */
final readonly class CapabilitySet
{
    /**
     * @param  array<string, bool>  $features
     * @param  array<string, mixed>  $limits
     * @param  list<string>  $grantedScopes
     */
    public function __construct(
        public array $features = [],
        public array $limits = [],
        public array $grantedScopes = [],
    ) {}

    public function supports(string $feature): bool
    {
        return (bool) ($this->features[$feature] ?? false);
    }

    public function limit(string $key): mixed
    {
        return $this->limits[$key] ?? null;
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->grantedScopes, true);
    }

    /**
     * Narrow by granted scopes: any feature whose required scopes are not all
     * present is switched off.
     *
     * @param  array<string, list<string>>  $featureScopeMap
     */
    public function narrowedByScopes(array $featureScopeMap): self
    {
        $features = $this->features;

        foreach ($featureScopeMap as $feature => $requiredScopes) {
            if (! ($features[$feature] ?? false)) {
                continue;
            }

            foreach ($requiredScopes as $scope) {
                if (! $this->hasScope($scope)) {
                    $features[$feature] = false;
                    break;
                }
            }
        }

        return new self($features, $this->limits, $this->grantedScopes);
    }

    /** @return list<string> */
    public function supportedFeatures(): array
    {
        return array_keys(array_filter($this->features));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'features' => $this->features,
            'limits' => $this->limits,
            'granted_scopes' => $this->grantedScopes,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (array) ($data['features'] ?? []),
            (array) ($data['limits'] ?? []),
            array_values((array) ($data['granted_scopes'] ?? [])),
        );
    }
}
