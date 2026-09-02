<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Exceptions\ImpersonationDenied;
use App\Domain\Platform\Services\ImpersonationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartImpersonationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entering and leaving a customer's account.
 *
 * `stop` is deliberately NOT behind the super-admin middleware. Once
 * impersonation begins the session's principal is the customer, who is not a
 * Super Admin -- gating the exit on that check would trap the admin inside the
 * account they are supporting.
 */
final class ImpersonationController extends Controller
{
    public function __construct(private readonly ImpersonationService $impersonation) {}

    public function store(StartImpersonationRequest $request, User $user): RedirectResponse
    {
        $request->user()->can('platform.impersonate') || abort(403);

        try {
            $this->impersonation->start(
                $request->user(),
                $user,
                $request->validated()['reason'],
                $request->session(),
            );
        } catch (ImpersonationDenied $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()
            ->route('agency.dashboard')
            ->with('status', 'You are now acting as '.$user->name.'. Everything you do is recorded.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $admin = $this->impersonation->stop($request->session());

        if ($admin === null) {
            // Either nothing was active, or the admin account is gone. Either
            // way there is no admin session to return to.
            return redirect()->route('login');
        }

        return redirect()
            ->route('admin.dashboard')
            ->with('status', 'Impersonation ended.');
    }
}
