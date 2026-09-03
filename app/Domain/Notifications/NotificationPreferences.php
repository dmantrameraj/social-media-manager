<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Decides which channels a given recipient wants a given event on.
 *
 * Preferences are stored only for AGENCY users: notification_preferences has a
 * user_id foreign key to `users`. Portal users have no preferences by design --
 * they receive exactly one kind of message ("something needs your review"), and
 * a client who does not want it should be removed from the brand rather than
 * left assigned and silently muted, which would look to the agency like the
 * client is ignoring them.
 */
final class NotificationPreferences
{
    /**
     * Channels to deliver this event on for this recipient.
     *
     * @return list<string>
     */
    public function channelsFor(Authenticatable $recipient, string $event): array
    {
        $definition = $this->definition($event);

        $defaults = (array) ($definition['defaults'] ?? []);

        // Portal users have no stored preferences; the defaults are the answer.
        if (! $recipient instanceof User) {
            return $this->enabledChannels($defaults);
        }

        $stored = DB::table('notification_preferences')
            ->where('user_id', $recipient->getKey())
            ->where('event_key', $event)
            ->pluck('enabled', 'channel')
            ->all();

        $resolved = [];

        foreach ((array) config('notifications.channels', []) as $channel) {
            /*
             | Stored preference wins; the catalogue default fills the gap.
             |
             | Absence must mean "default", not "off": a user who signed up
             | before an event existed has no row for it, and treating that as
             | opted-out would silently stop every new notification the product
             | ever adds.
             */
            $enabled = array_key_exists($channel, $stored)
                ? (bool) $stored[$channel]
                : (bool) ($defaults[$channel] ?? false);

            if ($enabled) {
                $resolved[] = $channel;
            }
        }

        return $resolved;
    }

    /** Which guard an event is addressed to. */
    public function audienceFor(string $event): string
    {
        return (string) ($this->definition($event)['audience'] ?? 'agency');
    }

    public function isClientEvent(string $event): bool
    {
        return $this->audienceFor($event) === 'client';
    }

    public function labelFor(string $event): string
    {
        return (string) ($this->definition($event)['label'] ?? $event);
    }

    /** @return list<string> */
    public function eventKeys(): array
    {
        return array_keys((array) config('notifications.events', []));
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return list<string>
     */
    private function enabledChannels(array $defaults): array
    {
        return array_values(array_filter(
            (array) config('notifications.channels', []),
            static fn (string $channel): bool => (bool) ($defaults[$channel] ?? false),
        ));
    }

    /**
     * A typo must not resolve to "notify nobody".
     *
     * Silence is the failure mode that never gets reported: the person who
     * should have been told simply is not, and nothing anywhere records that a
     * message was skipped.
     *
     * @return array<string, mixed>
     */
    private function definition(string $event): array
    {
        $events = (array) config('notifications.events', []);

        if (! array_key_exists($event, $events)) {
            throw new InvalidArgumentException("Unknown notification event [{$event}].");
        }

        return (array) $events[$event];
    }
}
