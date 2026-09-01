<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Enums\LoginEvent;
use App\Domain\Audit\Services\RecordLoginHistoryService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/**
 * Bridges Laravel's auth events to the login history.
 *
 * Runs synchronously and cheaply: a security log that lands seconds later, or
 * not at all because a queue worker died, is not a security log.
 *
 * Deliberately NOT in app/Listeners: Laravel auto-discovers listeners there,
 * which combined with the explicit Event::subscribe() in AppServiceProvider
 * registered it twice and wrote every auth event to the log twice. Explicit
 * registration is preferred for a security-critical listener, so the class
 * lives with its own domain instead.
 */
final class RecordAuthenticationEvent
{
    public function __construct(private readonly RecordLoginHistoryService $history) {}

    public function handleLogin(Login $event): void
    {
        $this->history->record(LoginEvent::Login, $event->user);

        // Last-seen data is convenience metadata, not the audit trail; the
        // login_histories row above is the record of truth.
        if (method_exists($event->user, 'forceFill')) {
            $event->user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => request()->ip(),
            ])->saveQuietly();
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user !== null) {
            $this->history->record(LoginEvent::Logout, $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        // $event->credentials contains the submitted password. It is
        // deliberately NOT passed on -- only the identifier is recorded.
        $this->history->record(
            LoginEvent::Failed,
            $event->user,
            $event->credentials['email'] ?? null,
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $this->history->record(
            LoginEvent::Locked,
            null,
            $event->request->input('email'),
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->history->record(LoginEvent::PasswordReset, $event->user);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
