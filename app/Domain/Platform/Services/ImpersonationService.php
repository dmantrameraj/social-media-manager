<?php

declare(strict_types=1);

namespace App\Domain\Platform\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Exceptions\ImpersonationDenied;
use App\Domain\Platform\Models\ImpersonationSession;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Support access to an agency user's account.
 *
 * The whole feature is a deliberate hole in the authorisation model, so the
 * guardrails are the feature:
 *
 *   - a reason is required and stored, never defaulted
 *   - a Super Admin may never impersonate another Super Admin, which would
 *     turn one compromised admin account into all of them
 *   - the session records who is really acting, so AuditLogger attributes
 *     every write to both identities
 *   - the session expires on a clock, not on the admin remembering to leave
 *
 * The admin's own identity is kept in the session rather than replaced, so
 * exiting restores it without a second login.
 */
final class ImpersonationService
{
    /** Session key holding the real principal. Read by AuditLogger. */
    public const IMPERSONATOR_KEY = 'impersonator_id';

    /** Session key holding the open impersonation_sessions row. */
    public const SESSION_KEY = 'impersonation_session_id';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws ImpersonationDenied
     */
    public function start(User $admin, User $target, string $reason, Session $session): ImpersonationSession
    {
        $this->assertMayImpersonate($admin, $target, $reason);

        return DB::transaction(function () use ($admin, $target, $reason, $session): ImpersonationSession {
            // An admin who starts a second impersonation without exiting the
            // first would otherwise leave an orphan row open forever.
            $this->closeOpenSessionsFor($admin, 'impersonation.superseded');

            $record = ImpersonationSession::query()->forceCreate([
                'super_admin_user_id' => $admin->getKey(),
                'target_type' => $target::class,
                'target_id' => $target->getKey(),
                'tenant_id' => $target->tenants()->first()?->getKey(),
                'reason' => $reason,
                'started_at' => now(),
                'ip' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 500),
            ]);

            $this->audit->log(
                'impersonation.started',
                $target,
                newValues: [
                    'impersonation_session_id' => $record->getKey(),
                    'target_user_id' => $target->getKey(),
                    'reason' => $reason,
                ],
                actor: $admin,
                tenantId: $record->tenant_id,
            );

            /*
             | Order matters. Auth::login() regenerates nothing by itself, but
             | the session id is cycled first to avoid fixation, and the
             | impersonator marker is written AFTER that -- writing it before
             | would put it on the session we are about to discard.
             */
            $session->regenerate();
            Auth::guard('web')->login($target);

            $session->put(self::IMPERSONATOR_KEY, $admin->getKey());
            $session->put(self::SESSION_KEY, $record->getKey());

            return $record;
        });
    }

    /**
     * Leave impersonation and restore the admin.
     *
     * Safe to call when nothing is active: exiting twice, or exiting a session
     * whose row has already been closed by the timeout sweeper, must not error.
     */
    public function stop(Session $session, string $action = 'impersonation.ended'): ?User
    {
        $adminId = $session->get(self::IMPERSONATOR_KEY);
        $recordId = $session->get(self::SESSION_KEY);

        $session->forget([self::IMPERSONATOR_KEY, self::SESSION_KEY]);

        if (! is_numeric($adminId)) {
            return null;
        }

        $admin = User::query()->find((int) $adminId);
        $record = is_numeric($recordId)
            ? ImpersonationSession::query()->find((int) $recordId)
            : null;

        if ($record !== null && $record->isOpen()) {
            $record->forceFill(['ended_at' => now()])->save();

            $this->audit->log(
                $action,
                null,
                newValues: [
                    'impersonation_session_id' => $record->getKey(),
                    'target_user_id' => $record->target_id,
                    'elapsed_minutes' => $record->elapsedMinutes(),
                ],
                actor: $admin,
                tenantId: $record->tenant_id,
            );
        }

        // The admin may have been deleted or demoted mid-session. Restoring a
        // stale principal would be worse than dropping to the login screen.
        if (! $admin instanceof User || ! $admin->isSuperAdmin()) {
            Auth::guard('web')->logout();

            return null;
        }

        $session->regenerate();
        Auth::guard('web')->login($admin);

        return $admin;
    }

    /** The row backing the current request's impersonation, if any. */
    public function current(Session $session): ?ImpersonationSession
    {
        $id = $session->get(self::SESSION_KEY);

        if (! is_numeric($id)) {
            return null;
        }

        $record = ImpersonationSession::query()->find((int) $id);

        return $record?->isOpen() === true ? $record : null;
    }

    public function isImpersonating(Session $session): bool
    {
        return is_numeric($session->get(self::IMPERSONATOR_KEY));
    }

    /**
     * @throws ImpersonationDenied
     */
    private function assertMayImpersonate(User $admin, User $target, string $reason): void
    {
        if (! $admin->isSuperAdmin()) {
            throw ImpersonationDenied::notAnAdministrator();
        }

        if (! $admin->hasTwoFactorEnabled()) {
            throw ImpersonationDenied::twoFactorRequired();
        }

        if (trim($reason) === '') {
            throw ImpersonationDenied::reasonRequired();
        }

        if ($target->isSuperAdmin()) {
            throw ImpersonationDenied::targetIsAdministrator();
        }

        if ($target->is($admin)) {
            throw ImpersonationDenied::self();
        }
    }

    private function closeOpenSessionsFor(User $admin, string $action): void
    {
        ImpersonationSession::query()
            ->open()
            ->where('super_admin_user_id', $admin->getKey())
            ->get()
            ->each(function (ImpersonationSession $stale) use ($admin, $action): void {
                $stale->forceFill(['ended_at' => now()])->save();

                $this->audit->log(
                    $action,
                    null,
                    newValues: [
                        'impersonation_session_id' => $stale->getKey(),
                        'target_user_id' => $stale->target_id,
                        'elapsed_minutes' => $stale->elapsedMinutes(),
                    ],
                    actor: $admin,
                    tenantId: $stale->tenant_id,
                );
            });
    }
}
