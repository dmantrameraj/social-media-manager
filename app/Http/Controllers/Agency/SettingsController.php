<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The workspace's own settings: name and timezone.
 *
 * `settings.view` and `settings.update` have been in the permission catalogue
 * since Step 5 and were used by nothing. Until this screen existed, an agency
 * that signed up with the wrong timezone was stuck with it -- and because
 * CreateCustomerService stamps each new brand from the tenant's timezone, the
 * mistake was inherited by every brand made afterwards with no way to correct
 * the source.
 */
final class SettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function edit(Request $request): View
    {
        $request->user()->can('settings.view') || abort(403);

        /*
         | Name and timezone only. `tenants.locale` exists in the schema and is
         | read by nothing, so a control for it would be a switch that changes
         | no observable behaviour -- worse than an absent one, because people
         | believe switches. It belongs here the day something consumes it.
         */
        return view('agency.settings.edit', [
            'title' => 'Workspace settings',
            'tenant' => $this->context->get(),
            'timezones' => timezone_identifiers_list(),
            'canUpdate' => $request->user()->can('settings.update'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        // A separate permission from settings.view on purpose: reading which
        // timezone the workspace runs on is not the same authority as changing
        // what every brand created afterwards will inherit.
        $request->user()->can('settings.update') || abort(403);

        $tenant = $this->context->get();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            // Validated against the system's own list rather than a regex: an
            // identifier that merely looks plausible still throws when Carbon
            // tries to use it, and it would do so on the scheduling path
            // rather than here.
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
        ]);

        $before = $tenant->only(['name', 'timezone']);

        $tenant->fill($validated);
        $tenant->save();

        $this->audit->log(
            'tenancy.settings_updated',
            $tenant,
            oldValues: $before,
            newValues: $validated,
            actor: $request->user(),
            tenantId: $tenant->getKey(),
        );

        /*
         | Deliberately does not touch existing brands. Their timezone is
         | snapshotted at creation so scheduling never walks the tenant
         | relation on a hot path, and rewriting them here would silently move
         | every already-scheduled post for every client. The view says so
         | plainly, because somebody changing this will otherwise assume it
         | fixed the brands they already have.
         */
        return back()->with('status', 'Workspace settings saved. Existing brands keep their own timezone.');
    }
}
