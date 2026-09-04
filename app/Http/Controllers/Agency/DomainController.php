<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Entitlements\EntitlementResolver;
use App\Domain\Platform\Enums\DomainType;
use App\Domain\Platform\Models\Domain;
use App\Domain\Platform\Services\VerifyDomainService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Custom domains for the client portal.
 *
 * The `domains` table shipped in Phase 1 as a schema stub with nothing
 * attached at all -- no model, no routes, and no host-based resolution.
 *
 * Certificates are NOT issued here. Provisioning belongs to the edge (Caddy,
 * nginx with certbot, a load balancer), and pretending otherwise would put a
 * status on screen that no code in this application can honour. What is
 * tracked is what the edge reports, so an agency can be told why their domain
 * is not serving yet instead of guessing.
 */
final class DomainController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementResolver $entitlements,
        private readonly VerifyDomainService $verifier,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('settings.view') || abort(403);

        return view('agency.settings.domains', [
            'title' => 'Domains',
            'domains' => Domain::query()->orderBy('hostname')->get(),
            // Same gate as branding: a custom portal domain is part of white
            // labelling, not a separate purchase.
            'entitled' => $this->entitlements->allows($this->context->get(), 'white_label.enabled'),
            'canUpdate' => $request->user()->can('settings.update'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->user()->can('settings.update') || abort(403);

        abort_unless(
            $this->entitlements->allows($this->context->get(), 'white_label.enabled'),
            403,
        );

        /*
         | Normalised BEFORE validation, not after.
         |
         | The regex accepts lowercase only, so validating first would reject
         | PORTAL.example outright -- and worse, `unique` would run against the
         | un-normalised value, letting somebody claim PORTAL.example while
         | portal.example already exists on a case-sensitive collation. DNS
         | treats them as the same name and so must this.
         */
        $request->merge([
            'hostname' => mb_strtolower(trim((string) $request->input('hostname', ''))),
        ]);

        $validated = $request->validate([
            /*
             | A hostname, not a URL. `unique` is global rather than scoped to
             | this tenant because the table says so and resolution demands it:
             | a hostname maps to exactly one agency or it maps to nothing.
             |
             | Lowercased first -- DNS is case-insensitive, so PORTAL.example
             | and portal.example are the same name and must not become two
             | rows that resolve differently.
             */
            'hostname' => [
                'required', 'string', 'max:190',
                'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/',
                'unique:domains,hostname',
            ],
        ], [
            'hostname.regex' => 'Enter a hostname like portal.youragency.com, without http:// or a path.',
            'hostname.unique' => 'That hostname is already in use.',
        ]);

        $domain = new Domain;

        $domain->forceFill([
            'tenant_id' => $this->context->get()->getKey(),
            'hostname' => $validated['hostname'],
            'type' => DomainType::Custom->value,
            'is_primary' => false,
            'verification_token' => Domain::newVerificationToken(),
            // Unverified, so it resolves nothing until DNS proves ownership.
            'verified_at' => null,
        ])->save();

        $this->audit->log(
            'domain.added',
            $domain,
            newValues: ['hostname' => $domain->hostname],
            actor: $request->user(),
            tenantId: $domain->tenant_id,
        );

        return back()->with('status', 'Domain added. Publish the TXT record below, then verify.');
    }

    public function verify(Request $request, Domain $domain): RedirectResponse
    {
        $request->user()->can('settings.update') || abort(403);

        // The tenant scope already hides another agency's domain, so a foreign
        // one and a missing one are indistinguishable from here.
        abort_unless($domain->tenant_id === $this->context->id(), 404);

        return $this->verifier->verify($domain)
            ? back()->with('status', 'Verified. Your portal will answer on this domain once its certificate is issued.')
            : back()->with('error', 'That TXT record was not found. DNS can take a while to propagate, so try again shortly.');
    }

    public function destroy(Request $request, Domain $domain): RedirectResponse
    {
        $request->user()->can('settings.update') || abort(403);
        abort_unless($domain->tenant_id === $this->context->id(), 404);

        $hostname = $domain->hostname;

        /*
         | Deleted outright rather than soft-deleted. hostname is globally
         | unique, and a soft-deleted row would hold the name for ever --
         | including against the agency that removed it by mistake and wants
         | it back.
         */
        $domain->delete();

        $this->audit->log(
            'domain.removed',
            null,
            oldValues: ['hostname' => $hostname],
            actor: $request->user(),
            tenantId: $this->context->id(),
        );

        return back()->with('status', 'Domain removed.');
    }
}
