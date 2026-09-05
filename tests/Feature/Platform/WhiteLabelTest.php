<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\BrandingSetting;
use App\Domain\Platform\Services\BrandingResolver;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | Per-agency white labelling.
 |
 | branding_settings shipped in Phase 1 as a documented schema stub and every
 | template already went through BrandingResolver -- but nothing read the
 | overrides, and nothing could write them. A client of Bright Digital logging
 | in to approve their posts saw the SaaS vendor's name rather than the agency
 | they actually hired.
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

    /*
     | Short enough to fit. primary_color is varchar(9) -- long enough for
     | #RRGGBBAA -- so a sprawling injection payload is refused by the COLUMN
     | before the resolver is reached, which is a defence worth knowing about
     | but not the one under test here. This value fits and is still not a
     | colour, so it reaches the resolver and must be rejected there.
     */
    $setting->forceFill(['primary_color' => 'red;a{}'])->save();

    expect(app(BrandingResolver::class)->primaryColor())
        ->toBe(config('branding.colors.primary'));
});

it('normalises a valid colour to lower case', function (): void {
    expect(BrandingSetting::normaliseColor('#0EA5E9'))->toBe('#0ea5e9')
        ->and(BrandingSetting::normaliseColor('not a colour'))->toBeNull()
        ->and(BrandingSetting::normaliseColor(null))->toBeNull();
});

// ------------------------------------------------------------------ editing

it('saves branding an agency enters', function (): void {
    // Reading was wired first; without this nothing could WRITE it, so an
    // entitled agency still had no way to use the feature.
    allowWhiteLabel();

    $this->actingAs($this->owner)
        ->from(route('agency.settings.branding'))
        ->put(route('agency.settings.branding.update'), [
            'app_name' => 'Bright Digital Social',
            'primary_color' => '#0EA5E9',
        ])
        ->assertRedirect(route('agency.settings.branding'));

    expect(app(BrandingResolver::class)->appName())->toBe('Bright Digital Social')
        // Normalised on the way in.
        ->and(app(BrandingResolver::class)->primaryColor())->toBe('#0ea5e9');
});

it('treats a cleared field as back to the default', function (): void {
    // An agency emptying the field wants the platform default, not a nameless
    // product.
    allowWhiteLabel();

    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital Social',
    ]);

    $this->actingAs($this->owner)
        ->put(route('agency.settings.branding.update'), ['app_name' => '  ']);

    expect(app(BrandingResolver::class)->appName())->toBe(config('branding.app_name'));
});

it('refuses a colour that is not hex', function (): void {
    // Rejected at the form as well as in the resolver: these reach a style
    // attribute, so a non-colour is a CSS injection.
    allowWhiteLabel();

    $this->actingAs($this->owner)
        ->put(route('agency.settings.branding.update'), [
            'primary_color' => 'red;a{}',
        ])
        ->assertSessionHasErrors('primary_color');
});

it('will not save branding without the entitlement', function (): void {
    /*
     | Checked on write as well as read. Letting an unentitled tenant save it
     | would mean their settings take effect the moment somebody grants the
     | flag for an unrelated reason.
     */
    $this->actingAs($this->owner)
        ->put(route('agency.settings.branding.update'), [
            'app_name' => 'Should not save',
        ])
        ->assertForbidden();

    expect(BrandingSetting::query()->count())->toBe(0);
});

it('shows the screen even without the entitlement', function (): void {
    // Hiding it leaves somebody who was sold the feature hunting for a setting
    // that appears not to exist.
    $this->actingAs($this->owner)
        ->get(route('agency.settings.branding'))
        ->assertOk()
        ->assertSee('not part of your current plan');
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

// ------------------------------------------- the fields nothing used to read

it('gives a client the agency support address, not the platform', function (): void {
    /*
     | support_email and secondary_color could be entered on the branding
     | screen, were validated, and were saved -- and no template read either.
     | The portal told a client "questions about your content go to your
     | agency" without giving them the address the agency had typed in, which
     | is the white-label promise stopping one field short.
     */
    allowWhiteLabel();

    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital',
        'support_email' => 'hello@brightdigital.test',
    ]);

    $portalUser = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->actingAs($portalUser, 'customer')
        ->get(route('portal.dashboard'))
        ->assertOk()
        ->assertSee('hello@brightdigital.test');
});

it('never shows the platform address to a branded agency client', function (): void {
    /*
     | The case that matters: the agency IS branded -- their name is on the
     | page -- and they have not set a support address. Falling back to the
     | platform's would put the vendor's address directly beneath the
     | agency's name, which is precisely what they are paying not to happen.
     |
     | With no branding row at all the portal is unbranded anyway, and the
     | configured address is the right one; that is a different case.
     */
    allowWhiteLabel();

    BrandingSetting::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'app_name' => 'Bright Digital',
        'support_email' => null,
    ]);

    $portalUser = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->actingAs($portalUser, 'customer')
        ->get(route('portal.dashboard'))
        ->assertOk()
        ->assertSee('Bright Digital')
        ->assertSee('go to your agency')
        ->assertDontSee((string) config('branding.support_email'));
});
