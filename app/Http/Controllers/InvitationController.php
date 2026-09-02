<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Tenancy\Exceptions\InvitationUnusable;
use App\Domain\Tenancy\Services\AcceptInvitationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accepting a team invitation.
 *
 * Authenticated but deliberately NOT tenant-scoped: the invitee is joining a
 * tenant they do not belong to yet, so ResolveTenant would 403 them before
 * they could accept.
 */
final class InvitationController
{
    public function show(string $token): View
    {
        // The token is never rendered back into the page body -- only into the
        // form action -- so it does not end up in copied page text or in a
        // referrer.
        return view('invitations.accept', [
            'title' => 'Join workspace',
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token, AcceptInvitationService $service): RedirectResponse
    {
        try {
            $invitation = $service->execute($token, $request->user());
        } catch (InvitationUnusable $e) {
            // The message says what to do next without revealing which check
            // failed.
            return redirect()->route('home')->with('error', $e->getMessage());
        }

        // Point the session at the workspace just joined, so the next request
        // resolves it without the user having to pick.
        $request->session()->put(
            (string) config('tenancy.resolution.session_key', 'tenant_id'),
            $invitation->tenant_id,
        );

        return redirect()
            ->route('agency.dashboard')
            ->with('status', 'You have joined the workspace.');
    }
}
