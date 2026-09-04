<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\AuditLogger;
use App\Domain\Social\Models\SocialAppCredential;
use App\Domain\Social\Models\SocialConnection;
use App\Http\Requests\Agency\StoreSocialCredentialRequest;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An agency's own developer apps.
 *
 * Closes `social_credentials.manage`, which has been in the permission
 * catalogue since Phase 1 governing nothing, and the social_app_credentials
 * table, which has existed since Phase 2 with no way to put a row in it. The
 * overview lists bring-your-own credentials as a differentiator; until now it
 * was a schema comment.
 *
 * THE RULES THIS SCREEN EXISTS TO KEEP (§64, docs/10-SECURITY.md §2)
 *
 *   - No value is ever rendered back. Not the secret, not the client id, not
 *     even masked -- a mask still confirms a length and tells an attacker when
 *     they have the right one.
 *   - An empty secret on update means "unchanged", so somebody editing a label
 *     does not have to re-type a secret they may not have to hand.
 *   - Audit entries record that a credential changed and never what it
 *     changed to. An audit log holding secrets is a second copy of them, in
 *     the one table designed to be read by people.
 *   - Only the Agency Owner. Agency Admin is explicitly excluded from this
 *     permission in the role catalogue, and that is deliberate.
 */
final class SocialCredentialController
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('social_credentials.manage') || abort(403);

        $credentials = SocialAppCredential::query()
            ->orderBy('provider_key')
            ->orderBy('label')
            ->get();

        return view('agency.social.credentials', [
            'title' => 'Developer apps',
            // toSafeArray(), so no template can accidentally reach a value
            // that is not supposed to leave this server.
            'credentials' => $credentials->map(fn (SocialAppCredential $c): array => $c->toSafeArray() + [
                'in_use' => $this->connectionsUsing($c) > 0,
            ]),
            'providers' => array_keys((array) config('social.providers', [])),
        ]);
    }

    public function store(StoreSocialCredentialRequest $request): RedirectResponse
    {
        $request->user()->can('social_credentials.manage') || abort(403);

        $credential = new SocialAppCredential;

        $credential->forceFill([
            'tenant_id' => $this->context->id(),
            'provider_key' => $request->string('provider_key')->toString(),
            'label' => $request->string('label')->toString(),
            'client_id' => $request->string('client_id')->toString(),
            'client_secret' => $request->string('client_secret')->toString(),
            'is_active' => true,
            'created_by_user_id' => $request->user()->getKey(),
        ])->save();

        /*
         | The action and the label, never the values. §64 is explicit that no
         | secret may appear in an audit record, and "which app was added" is
         | the question an audit trail is actually asked.
         */
        $this->audit->log(
            action: 'social_credential.created',
            auditable: $credential,
            newValues: [
                'provider_key' => $credential->provider_key,
                'label' => $credential->label,
            ],
            actor: $request->user(),
        );

        return back()->with('status', 'Developer app saved. Connect an account to test it.');
    }

    /**
     * Change the label, the secret, or both.
     */
    public function update(Request $request, SocialAppCredential $credential): RedirectResponse
    {
        $request->user()->can('social_credentials.manage') || abort(403);
        $this->assertReachable($credential);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            // Optional, and empty means unchanged -- see the class docblock.
            'client_id' => ['nullable', 'string', 'max:500'],
            'client_secret' => ['nullable', 'string', 'max:500'],
        ]);

        $changed = ['label'];

        $credential->forceFill(['label' => $validated['label']]);

        if (($validated['client_id'] ?? '') !== '') {
            $credential->forceFill(['client_id' => $validated['client_id']]);
            $changed[] = 'client_id';
        }

        if (($validated['client_secret'] ?? '') !== '') {
            $credential->forceFill([
                'client_secret' => $validated['client_secret'],
                /*
                 | A new secret is an unverified app again. Leaving the old
                 | verification in place would show a green tick against
                 | credentials nobody has ever successfully used.
                 */
                'verified_at' => null,
                'last_verify_error' => null,
            ]);
            $changed[] = 'client_secret';
        }

        $credential->save();

        $this->audit->log(
            action: 'social_credential.updated',
            auditable: $credential,
            // WHICH fields changed, never their values.
            newValues: ['changed' => $changed],
            actor: $request->user(),
        );

        return back()->with('status', 'Developer app updated.');
    }

    /**
     * Turn an app off without deleting it.
     *
     * Deactivating is the reversible half of deletion, and the one an agency
     * wants while they work out whether a new app is configured correctly.
     */
    public function toggle(Request $request, SocialAppCredential $credential): RedirectResponse
    {
        $request->user()->can('social_credentials.manage') || abort(403);
        $this->assertReachable($credential);

        $credential->forceFill(['is_active' => ! $credential->is_active])->save();

        $this->audit->log(
            action: $credential->is_active
                ? 'social_credential.activated'
                : 'social_credential.deactivated',
            auditable: $credential,
            actor: $request->user(),
        );

        return back()->with('status', $credential->is_active
            ? 'Developer app in use again.'
            : 'Developer app turned off. New connections will use the platform app.');
    }

    public function destroy(Request $request, SocialAppCredential $credential): RedirectResponse
    {
        $request->user()->can('social_credentials.manage') || abort(403);
        $this->assertReachable($credential);

        /*
         | Refused while accounts are connected through it.
         |
         | The foreign key is nullOnDelete, so deleting would not break a row
         | -- it would quietly detach live connections from the app that
         | granted them, and the failure would surface later as a refresh that
         | cannot be performed, with nothing left to explain why.
         */
        if ($this->connectionsUsing($credential) > 0) {
            return back()->with(
                'error',
                'Accounts are still connected through this app. Disconnect them first, or turn the app off instead.',
            );
        }

        $credential->delete();

        $this->audit->log(
            action: 'social_credential.deleted',
            auditable: $credential,
            oldValues: ['provider_key' => $credential->provider_key, 'label' => $credential->label],
            actor: $request->user(),
        );

        return back()->with('status', 'Developer app removed.');
    }

    private function connectionsUsing(SocialAppCredential $credential): int
    {
        return SocialConnection::query()
            ->where('social_app_credential_id', $credential->getKey())
            ->count();
    }

    /**
     * The tenant scope already hides another agency's row, so this is a
     * belt-and-braces assertion rather than the only guard -- and a 404 rather
     * than a 403, because whether a credential exists is not theirs to learn.
     */
    private function assertReachable(SocialAppCredential $credential): void
    {
        abort_unless($credential->tenant_id === $this->context->id(), 404);
    }
}
