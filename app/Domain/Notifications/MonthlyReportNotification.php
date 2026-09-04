<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Analytics\Models\ReportShare;
use App\Domain\Platform\Services\BrandingResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Here is what we did last month."
 *
 * Carries the share link rather than the figures. A month of numbers in an
 * email is unreadable on a phone and impossible to keep current -- and the
 * link already knows how to expire, be revoked, and show exactly the window it
 * was minted for.
 */
final class MonthlyReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $url,
        private readonly string $brandName,
        private readonly string $period,
        private readonly ReportShare $share,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        /*
         | Mail only, deliberately. A database notification would put the link
         | in the portal's own bell -- where the recipient is already signed in
         | and can see the live dashboard, making the link redundant. The point
         | of this is to reach somebody who is NOT looking at the product.
         */
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $branding = app(BrandingResolver::class);

        return (new MailMessage)
            ->subject($this->brandName.': your '.$this->period.' report')
            ->greeting('Hello')
            ->line('Here is how '.$this->brandName.' performed in '.$this->period.'.')
            ->action('View the report', $this->url)
            /*
             | The expiry is stated. A client who finds the mail in March and
             | clicks a dead link should have been told when it would stop
             | working, not left wondering whether something is broken.
             */
            ->line('This link works until '.$this->share->expires_at->toFormattedDateString().'.')
            ->salutation('— '.$branding->appName());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        // The URL is deliberately absent: a stored notification row is a
        // second copy of a working link, and the token exists once by design.
        return [
            'type' => 'report.monthly',
            'brand' => $this->brandName,
            'period' => $this->period,
        ];
    }
}
