<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\BrandingSetting;
use App\Domain\Platform\Services\BrandingResolver;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | Per-agency white labelling.
 |
 | BrandingResolver has said "tenant overrides are read from branding_settings
 | once that feature ships" since Phase 1, and every template already went
 | through it -- but the table never existed, so a client of Bright Digital
 | logging in to approve their posts saw the SaaS vendor's name rather than the
 | agency they actually hired.
 */

beforeEach(function (): void {
    seedPermissions();

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $this->owner = $owner->fresh();
    actingForTenant($this->tenant);
});

/** Turn the sold feature on for this tenant. */
function allowWhiteLabel(): void
{
    givePlanFlag(test()->tenant->getKey(), 'white_label.enabled');
    app(EntitlementResolver::class)->forget(test()->tenant);
}

it('shows the platform name when nothing is overridden', function (): void {
    expect(app(BrandingResolver::class)->appName())
        ->toBe(config('branding.app_name'));
});

it('shows the agency name once they set one', function (): void {
    allowWhiteLabel();

    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital Social',
    ]);

    expect(app(BrandingResolver::class)->appName())->toBe('Bright Digital Social');
});

it('stops applying branding when the plan no longer includes it', function (): void {
    /*
     | The entitlement is checked on every resolve rather than trusted from the
     | row existing. White labelling is sold; a plan that drops it must stop
     | applying, or the feature is bought once and kept for ever.
     */
    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital Social',
    ]);

    // No entitlement granted.
    expect(app(BrandingResolver::class)->appName())->toBe(config('branding.app_name'));
});

it('keeps the saved branding through a downgrade', function (): void {
    // Kept, not deleted: upgrading again restores what they had rather than
    // asking them to type it back in.
    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital Social',
    ]);

    expect(app(BrandingResolver::class)->appName())->toBe(config('branding.app_name'));

    allowWhiteLabel();

    expect(app(BrandingResolver::class)->appName())->toBe('Bright Digital Social');
});

it('falls back for each field independently', function (): void {
    // Every field is nullable and null means "platform default", so an agency
    // can rename the product without also having to choose colours.
    allowWhiteLabel();

    BrandingSetting::factory()->nameOnly()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital Social',
    ]);

    $branding = app(BrandingResolver::class);

    expect($branding->appName())->toBe('Bright Digital Social')
        ->and($branding->primaryColor())->toBe(config('branding.colors.primary'));
});

it('refuses a colour that is not a colour', function (): void {
    /*
     | These are interpolated into a style attribute, so a non-colour is a CSS
     | injection rather than a cosmetic mistake. Checked in the resolver as
     | well as at the form, because a row written by a seeder, a console
     | command or a future import passed no form validation at all.
     */
    allowWhiteLabel();

    $setting = BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $setting->forceFill(['primary_color' => 'red; background:url(//evil)'])->save();

    expect(app(BrandingResolver::class)->primaryColor())
        ->toBe(config('branding.colors.primary'));
});

it('normalises a valid colour to lower case', function (): void {
    expect(BrandingSetting::normaliseColor('#0EA5E9'))->toBe('#0ea5e9')
        ->and(BrandingSetting::normaliseColor('not a colour'))->toBeNull()
        ->and(BrandingSetting::normaliseColor(null))->toBeNull();
});

it('never shows one agency branding to another', function (): void {
    allowWhiteLabel();

    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital Social',
    ]);

    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    expect(app(BrandingResolver::class)->appName())->toBe(config('branding.app_name'));
});
