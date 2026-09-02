<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Support\TenantContext;

/**
 * Resolves the branding shown to the current viewer.
 *
 * Every template goes through this rather than reading config directly, so
 * white labelling (Phase 8) becomes a change here and nowhere else. A hundred
 * Blade files each hardcoding the platform name is what makes white labelling
 * a rewrite instead of a feature.
 *
 * Tenant overrides are read from branding_settings once that feature ships;
 * until then this returns the platform defaults, and the templates do not care
 * which.
 */
final class BrandingResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function appName(): string
    {
        return (string) config('branding.app_name', config('app.name'));
    }

    public function primaryColor(): string
    {
        return (string) config('branding.colors.primary', '#4f46e5');
    }

    public function supportEmail(): string
    {
        return (string) config('branding.support_email', '');
    }

    /** Initials used as a placeholder mark before a logo is uploaded. */
    public function initials(): string
    {
        $words = preg_split('/\s+/', $this->appName()) ?: [];
        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr((string) $word, 0, 1));
        }

        return $initials !== '' ? $initials : 'S';
    }

    /** The workspace name shown in the sidebar, when one is resolved. */
    public function workspaceName(): ?string
    {
        return $this->context->hasTenant() ? $this->context->get()->name : null;
    }
}
