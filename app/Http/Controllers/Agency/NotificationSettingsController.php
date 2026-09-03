<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Notifications\NotificationPreferences;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A user's own notification preferences.
 *
 * No permission gate, for the same reason the notification list has none:
 * these are the signed-in user's own settings. A permission that let one team
 * member edit another's would be a stranger feature than the one it protects.
 *
 * Agency users only. notification_preferences has a foreign key to `users`, and
 * clients receive exactly one kind of message -- a client who does not want it
 * should be removed from the brand rather than left assigned and silently
 * muted, which would look to the agency like they are ignoring them.
 */
final class NotificationSettingsController extends Controller
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function edit(Request $request): View
    {
        $user = $request->user();

        $stored = DB::table('notification_preferences')
            ->where('user_id', $user->getKey())
            ->get()
            ->groupBy('event_key');

        $rows = [];

        foreach ($this->preferences->eventKeys() as $event) {
            // Only agency-audience events are shown. A client event on this
            // screen would be a switch that does nothing, which is worse than
            // an absent one: people believe switches.
            if ($this->preferences->isClientEvent($event)) {
                continue;
            }

            $rows[] = [
                'key' => $event,
                'label' => $this->preferences->labelFor($event),
                'channels' => $this->preferences->channelsFor($user, $event),
                'stored' => $stored->has($event),
            ];
        }

        return view('agency.notifications.settings', [
            'title' => 'Notification settings',
            'events' => $rows,
            'channels' => (array) config('notifications.channels', []),
        ]);
    }

    public function update(Request $request, TenantContext $context): RedirectResponse
    {
        $user = $request->user();

        $submitted = (array) $request->input('prefs', []);

        $channels = (array) config('notifications.channels', []);

        DB::transaction(function () use ($user, $submitted, $channels, $context): void {
            foreach ($this->preferences->eventKeys() as $event) {
                if ($this->preferences->isClientEvent($event)) {
                    continue;
                }

                foreach ($channels as $channel) {
                    /*
                     | An unchecked checkbox submits nothing, so absence in the
                     | payload means OFF here -- the opposite of what absence
                     | means in the database, where a missing row means "use the
                     | default".
                     |
                     | That is why every combination is written explicitly on
                     | save rather than only the checked ones: once a user has
                     | visited this screen, their choices are recorded, and a
                     | later change to a catalogue default must not silently
                     | override what they chose.
                     */
                    $enabled = (bool) ($submitted[$event][$channel] ?? false);

                    DB::table('notification_preferences')->updateOrInsert(
                        [
                            'user_id' => $user->getKey(),
                            'event_key' => $event,
                            'channel' => $channel,
                        ],
                        [
                            'tenant_id' => $context->id(),
                            'enabled' => $enabled,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ],
                    );
                }
            }
        });

        return back()->with('status', 'Notification settings saved.');
    }
}
