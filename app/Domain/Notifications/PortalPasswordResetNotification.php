<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Platform\Services\BrandingResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The reset link a client receives.
 *
 * This class exists because Laravel's own ResetPassword notification builds
 * `route('password.reset', ...)` -- the AGENCY reset form, which is backed by
 * the `users` broker and the `web` guard. A client following that link would
 * reach a page where their token is meaningless and their email unknown, and be
 * told the token is invalid.
 *
 * The same shape of mistake as sending portal guests to the agency login: two
 * surfaces, one hardcoded route, and the failure only appears for the audience
 * least able to explain it.
 */
final class PortalPasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token)
    {
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // Mail only, and no preference check: a reset link the recipient asked
        // for seconds ago is not a notification anyone opts out of.
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = app(BrandingResolver::class)->appName();

        $minutes = (int) config('auth.passwords.customers.expire', 60);

        return (new MailMessage)
            ->subject('Reset your '.$appName.' password')
            ->line('You asked to reset the password for your client account.')
            ->action('Choose a new password', route('portal.password.reset', [
                'token' => $this->token,
                // Carried in the URL because the reset form must submit the
                // same address the token was issued for; the broker checks
                // both together.
                'email' => $notifiable->getEmailForPasswordReset(),
            ]))
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not ask for this, nothing has changed and you can ignore this email.');
    }
}
