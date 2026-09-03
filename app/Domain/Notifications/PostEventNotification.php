<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notification class for the whole post event set.
 *
 * Seven near-identical classes differing only in a subject line is how the
 * copy drifts and how one of them quietly forgets to check preferences. The
 * event key carries the difference; the delivery rules are written once.
 *
 * Queued: a slow mail server must never delay the transaction that produced
 * the event, and `$afterCommit` means a job cannot run against a row that has
 * not been committed yet.
 */
final class PostEventNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $event,
        public readonly int $postId,
        /*
         | The ROUTE key, carried separately from the integer id.
         |
         | Posts bind by ULID, so route('agency.posts.show', $postId) builds
         | /app/content/19 -- a URL that 404s. The integer stays for joins and
         | for reading the trail; this is what a link is built from.
         */
        public readonly string $postRouteKey,
        public readonly string $postTitle,
        public readonly string $brandName,
        public readonly ?string $comment = null,
    ) {
        $this->onQueue((string) config('notifications.queue', 'notifications'));

        /*
         | Dispatch only after the surrounding transaction commits.
         |
         | Without this a queue worker can pick the job up and query a post that
         | does not exist yet -- a race that only shows up under load and reads
         | in production as a phantom "post not found".
         |
         | Set through Queueable's own method rather than by redeclaring the
         | property: the trait already declares `public $afterCommit` untyped,
         | and a typed redeclaration here is a fatal composition error that
         | takes down every notification in the application.
         */
        $this->afterCommit();
    }

    public static function for(string $event, Post $post, ?string $comment = null): self
    {
        // Resolved to a concrete type rather than chained: the relation can be
        // absent (a brand deleted between the transition and delivery), and
        // "your brand" reads better in that case than an empty subject line.
        $customer = $post->customer;

        return new self(
            event: $event,
            postId: $post->getKey(),
            postRouteKey: (string) $post->getRouteKey(),
            /*
             | The title is snapshotted rather than the model being carried.
             |
             | A queued notification is serialized: passing the Post would
             | re-query it at send time, so a post edited or deleted between the
             | event and delivery would produce a message describing something
             | that never happened.
             */
            postTitle: $post->title ?: 'Untitled post',
            brandName: $customer instanceof Customer ? $customer->name : 'your brand',
            comment: $comment,
        );
    }

    /**
     * Channels are resolved per recipient, so one person's preferences never
     * decide another's.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferences::class)->channelsFor($notifiable, $this->event);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $preferences = app(NotificationPreferences::class);
        $isClient = $preferences->isClientEvent($this->event);

        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting($this->greetingFor($notifiable))
            ->line($this->body());

        if ($this->comment !== null && trim($this->comment) !== '') {
            // The client's own words, not a paraphrase: "they asked for
            // changes" without the changes is a message that generates a
            // second message asking what they were.
            $mail->line('They said: "'.trim($this->comment).'"');
        }

        return $mail
            ->action(
                $isClient ? 'Review it' : 'Open the post',
                $isClient
                    ? route('portal.posts.show', $this->postRouteKey)
                    : route('agency.posts.show', $this->postRouteKey),
            )
            ->line($isClient
                ? 'Nothing is published until you approve it.'
                : 'You can change which emails you receive in your notification settings.');
    }

    /**
     * The in-app record.
     *
     * Deliberately flat scalars rather than a serialized model: this row is
     * read months later, and it must still render after the post it describes
     * has been edited or deleted.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'post_id' => $this->postId,
            'post_route_key' => $this->postRouteKey,
            'post_title' => $this->postTitle,
            'brand_name' => $this->brandName,
            'comment' => $this->comment,
            'message' => $this->body(),
        ];
    }

    /**
     * Both notifiable types carry a name, but the framework types this as a
     * bare object, so it is read defensively rather than assumed.
     */
    private function greetingFor(object $notifiable): string
    {
        $name = ($notifiable instanceof User || $notifiable instanceof CustomerPortalUser)
            ? trim((string) $notifiable->name)
            : '';

        return $name === '' ? 'Hello,' : 'Hello '.$name.',';
    }

    private function subject(): string
    {
        return match ($this->event) {
            'post.client_review' => $this->brandName.': content ready for your review',
            'post.client_approved' => 'Approved: '.$this->postTitle,
            'post.client_rejected' => 'Rejected: '.$this->postTitle,
            'post.changes_requested' => 'Changes requested: '.$this->postTitle,
            'post.publish_failed' => 'Failed to publish: '.$this->postTitle,
            'post.published' => 'Published: '.$this->postTitle,
            default => $this->postTitle,
        };
    }

    private function body(): string
    {
        return match ($this->event) {
            'post.client_review' => "Your agency has sent a post for {$this->brandName} for you to review.",
            'post.client_approved' => "The client approved \"{$this->postTitle}\" for {$this->brandName}.",
            'post.client_rejected' => "The client rejected \"{$this->postTitle}\" for {$this->brandName}.",
            'post.changes_requested' => "The client asked for changes to \"{$this->postTitle}\" for {$this->brandName}.",
            'post.publish_failed' => "\"{$this->postTitle}\" could not be published for {$this->brandName}.",
            'post.published' => "\"{$this->postTitle}\" is live for {$this->brandName}.",
            default => $this->postTitle,
        };
    }
}
