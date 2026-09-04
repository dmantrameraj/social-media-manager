<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Platform\Models\BrandingSetting;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where an agency sets what their clients see.
 *
 * branding_settings shipped in Phase 1 as a documented schema stub, and the
 * previous commit taught BrandingResolver to read it -- but nothing could
 * WRITE it. An agency entitled to white labelling still had no way to use it,
 * which is the same "one wire short" shape the stub was warning about.
 *
 * The client portal is where this matters. Somebody logging in to approve
 * their posts should see the agency they hired, not the vendor behind it.
 */
final class BrandingController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementResolver $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): View
    {
        $request->user()->can('settings.view') || abort(403);

        $tenant = $this->context->get();

        return view('agency.settings.branding', [
            'title' => 'Branding',
            'branding' => BrandingSetting::query()
                ->where('tenant_id', $tenant->getKey())
                ->first(),
            /*
             | The screen is shown either way. A plan that does not include
             | white labelling gets the form disabled and an explanation --
             | hiding it entirely leaves somebody who was sold the feature
             | hunting for a setting that appears to not exist.
             */
            'entitled' => $this->entitlements->allows($tenant, 'white_label.enabled'),
            'canUpdate' => $request->user()->can('settings.update'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->user()->can('settings.update') || abort(403);

        $tenant = $this->context->get();

        /*
         | Checked on write as well as on read. The resolver already refuses to
         | APPLY branding without the entitlement, but letting an unentitled
         | tenant save it would mean their settings silently take effect the
         | moment somebody grants the flag for an unrelated reason.
         */
        abort_unless(
            $this->entitlements->allows($tenant, 'white_label.enabled'),
            403,
        );

        $validated = $request->validate([
            'app_name' => ['nullable', 'string', 'max:120'],
            'support_email' => ['nullable', 'email', 'max:190'],
            /*
             | Hex only, and the same shape BrandingSetting::normaliseColor()
             | enforces. These reach a style attribute, so anything else is a
             | CSS injection rather than a cosmetic mistake -- validated here
             | AND normalised again on read, because a row written by a seeder
             | or an import never passes through this form.
             */
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'primary_color.regex' => 'Use a six-digit hex colour, like #0ea5e9.',
            'secondary_color.regex' => 'Use a six-digit hex colour, like #0f172a.',
        ]);

        $setting = BrandingSetting::query()
            ->where('tenant_id', $tenant->getKey())
            ->first() ?? new BrandingSetting;

        $setting->forceFill([
            'tenant_id' => $tenant->getKey(),
            /*
             | Blank clears back to the platform default rather than storing an
             | empty string. An agency emptying the field wants the default
             | back, not a nameless product.
             */
            'app_name' => $this->blankToNull($validated['app_name'] ?? null),
            'support_email' => $this->blankToNull($validated['support_email'] ?? null),
            'primary_color' => BrandingSetting::normaliseColor($validated['primary_color'] ?? null),
            'secondary_color' => BrandingSetting::normaliseColor($validated['secondary_color'] ?? null),
        ])->save();

        // What changed, not what it changed to: branding is not a secret, but
        // an audit entry that restates the whole row on every save is noise.
        $this->audit->log(
            'branding.updated',
            $setting,
            newValues: ['app_name' => $setting->app_name],
            actor: $request->user(),
            tenantId: $tenant->getKey(),
        );

        return back()->with('status', 'Branding saved. Your clients will see it on their next visit.');
    }

    private function blankToNull(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
