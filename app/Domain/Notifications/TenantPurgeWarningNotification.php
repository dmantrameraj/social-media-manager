<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Platform\Services\BrandingResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Tells an agency that their data is about to be destroyed.
 *
 * The purge itself shipped before this did, which meant a daily job deleting
 * every cancelled agency's content with no warning at all. The clock only
 * starts at cancellation, so nobody loses data they did not choose to stop
 * paying for -- but the difference between a policy and an ambush is whether
 * anybody was told.
 *
 * Deliberately NOT routed through NotificationPreferences. Every other
 * notification in this application can be switched off, and this one must not
 * be: "your data is deleted in seven days" is not a preference, and an
 * unsubscribe made months earlier for post updates must not silently suppress
 * it.
 */
final class TenantPurgeWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly Carbon $purgeAfter,
        public readonly int $daysRemaining,
    ) {
        $this->onQueue((string) config('notifications.queue', 'notifications'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        /*
         | Mail AND database. The in-app copy is not redundant: a cancelled
         | agency's billing contact may have left, and the person who logs in to
         | reactivate should see it without needing the original email.
         */
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = app(BrandingResolver::class)->appName();

        $date = $this->purgeAfter->toFormattedDateString();

        $message = (new MailMessage)
            /*
             | The subject states the deadline rather than teasing it. This mail
             | competes with everything else in an inbox, and the one thing it
             | has to achieve is being read before the date passes.
             */
            ->subject("Your {$appName} data will be deleted on {$date}")
            ->greeting('Hello')
            ->line("Your {$appName} workspace **{$this->tenantName}** was cancelled, and the retention period is nearly over.");

        $message->line($this->daysRemaining <= 1
            ? 'Everything below will be permanently deleted **tomorrow**.'
            : "Everything below will be permanently deleted in **{$this->daysRemaining} days**, on {$date}.");

        // Named specifically. "Your data" is easy to skim past; "every post,
        // image and client login" is what makes somebody act.
        $message
            ->line('This includes every brand, post, scheduled item, uploaded image and client login in the workspace, along with the connections to your social accounts.')
            ->line('**This cannot be undone.** We keep no backup after this date.')
            ->action('Reactivate your workspace', route('agency.billing'))
            ->line('Reactivating before '.$date.' keeps everything exactly as it is.')
            ->line('If you want a copy of your content instead, reply to this message before the date above and we will help you export it.');

        return $message;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'tenant.purge_warning',
            'tenant_name' => $this->tenantName,
            'purge_after' => $this->purgeAfter->toIso8601String(),
            'days_remaining' => $this->daysRemaining,
        ];
    }
}
