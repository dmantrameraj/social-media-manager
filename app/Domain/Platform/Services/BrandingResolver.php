<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Platform\Models\BrandingSetting;
use App\Support\TenantContext;

/**
 * Resolves the branding shown to the current viewer.
 *
 * Every template goes through this rather than reading config directly, so
 * white labelling (Phase 8) becomes a change here and nowhere else. A hundred
 * Blade files each hardcoding the platform name is what makes white labelling
 * a rewrite instead of a feature.
 *
 * Tenant overrides now come from branding_settings, and no template changed
 * to make that happen -- which was the point of routing every one of them
 * through here in the first place.
 *
 * Overrides are gated on the white_label.enabled entitlement, checked on each
 * resolve rather than trusted from the row's existence. An agency that
 * downgrades keeps its saved branding but stops being shown it, so upgrading
 * again restores what they had instead of asking them to type it back in.
 */
final class BrandingResolver
{
    /** Resolved once per request: every layout asks for several of these. */
    private ?BrandingSetting $cached = null;

    private bool $looked = false;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /**
     * This tenant's overrides, if they are entitled to them.
     */
    private function overrides(): ?BrandingSetting
    {
        if ($this->looked) {
            return $this->cached;
        }

        $this->looked = true;

        if (! $this->context->hasTenant()) {
            return $this->cached = null;
        }

        $tenant = $this->context->get();

        /*
         | The entitlement, not the row. White labelling is sold, and a plan
         | that no longer includes it must stop applying -- otherwise the
         | feature is bought once and kept for ever.
         */
        if (! $this->entitlements->allows($tenant, 'white_label.enabled')) {
            return $this->cached = null;
        }

        return $this->cached = BrandingSetting::query()
            ->where('tenant_id', $tenant->getKey())
            ->first();
    }

    public function appName(): string
    {
        // Blank is not an override. An agency that clears the field wants the
        // default back, not a nameless product.
        $override = $this->overrides()?->app_name;

        return $override !== null && trim($override) !== ''
            ? $override
            : (string) config('branding.app_name', config('app.name'));
    }

    public function primaryColor(): string
    {
        /*
         | Normalised again here, not merely at the form. This value is
         | interpolated into a style attribute, and a row written by a seeder,
         | a console command or a future import passed no form validation at
         | all -- so anything that is not a hex colour falls back rather than
         | reaching a template.
         */
        return BrandingSetting::normaliseColor($this->overrides()?->primary_color)
            ?? (string) config('branding.colors.primary', '#4f46e5');
    }

    public function secondaryColor(): string
    {
        return BrandingSetting::normaliseColor($this->overrides()?->secondary_color)
            ?? (string) config('branding.colors.secondary', '#0f172a');
    }

    public function supportEmail(): string
    {
        $override = $this->overrides()?->support_email;

        return $override !== null && trim($override) !== ''
            ? $override
            : (string) config('branding.support_email', '');
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
