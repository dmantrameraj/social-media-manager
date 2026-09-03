<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The agency's in-app notification list.
 *
 * Only the agency has one. The portal's dashboard already answers "what needs
 * me" with the posts awaiting review, and a second list there would be the
 * "client portal grows into a dashboard" mistake -- see
 * docs/PHASE-1-STEP-14-CLIENT-PORTAL.md §1.
 *
 * No permission gate: these are the signed-in user's OWN notifications, read
 * through the relation, so there is nothing here another permission could
 * usefully protect. The scoping is the identity itself.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $filter = (string) $request->query('show', 'all');

        $query = $user->notifications()->getQuery();

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }

        return view('agency.notifications.index', [
            'title' => 'Notifications',
            'notifications' => $query
                ->orderByDesc('created_at')
                ->paginate(25)
                ->withQueryString(),
            'unreadCount' => $user->unreadNotifications()->count(),
            'filter' => $filter,
        ]);
    }

    /**
     * Mark one read and go where it points.
     *
     * Read-and-open is a single action because they are a single intention: a
     * notification that stays unread after you have followed it is noise, and
     * one that needs two clicks to clear is noise people learn to ignore.
     */
    public function read(Request $request, string $id): RedirectResponse
    {
        // Found through the relation, so a notification belonging to someone
        // else is simply not there -- no ownership check to forget.
        $notification = $request->user()->notifications()->whereKey($id)->first();

        abort_if($notification === null, 404);

        $notification->markAsRead();

        /*
         | The ROUTE key, not the integer id. Posts bind by ULID, so
         | route('agency.posts.show', 19) builds /app/content/19 -- a URL that
         | 404s. Older rows written before the key was stored fall back to the
         | list rather than to a broken link.
         */
        $routeKey = $notification->data['post_route_key'] ?? null;

        return is_string($routeKey) && $routeKey !== ''
            ? redirect()->route('agency.posts.show', $routeKey)
            : redirect()->route('agency.notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = $request->user()->unreadNotifications()->count();

        $request->user()->unreadNotifications->markAsRead();

        return redirect()
            ->route('agency.notifications.index')
            ->with('status', $count === 0
                ? 'Nothing was unread.'
                : "Marked {$count} ".str('notification')->plural($count).' as read.');
    }
}
