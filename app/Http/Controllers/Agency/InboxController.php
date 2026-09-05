<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Customers\Models\Customer;
use App\Domain\Engagement\Enums\InboxStatus;
use App\Domain\Engagement\Models\InboxMessage;
use App\Domain\Engagement\Models\InboxThread;
use App\Domain\Engagement\Services\ReplyToThreadService;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Contracts\SupportsInbox;
use App\Domain\Social\Exceptions\UnknownProvider;
use App\Domain\Social\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The unified inbox.
 *
 * Comments and messages from every connected account in one queue, because an
 * agency answering four networks in four browser tabs misses the one nobody
 * had open.
 */
final class InboxController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ProviderRegistry $providers,
        private readonly ReplyToThreadService $replies,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('inbox.view') || abort(403);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:open,pending,closed'],
            'brand' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
            'unsent' => ['nullable', 'boolean'],
        ]);

        $brands = $this->visibleBrands($request);

        $query = InboxThread::query()
            ->with(['socialAccount', 'customer', 'assignee'])
            /*
             | Bounded to what this person can reach before any filter is
             | applied. A member assigned to one client must not read another's
             | conversations by supplying a brand id.
             */
            ->whereIn('customer_id', $brands->modelKeys())
            ->orderByDesc('last_message_at');

        /*
         | Threads holding a reply that never reached the network.
         |
         | A failed reply was already marked as such and shown inside its own
         | thread -- but only there, so the only way to find one was to open
         | the thread you had no reason to open. An agency believes it answered
         | a customer it did not answer, which is worse than never having
         | replied: nobody is waiting for a reply they think was sent.
         |
         | Counted across every thread the person can reach, before the status
         | filter, because a failed reply on a thread somebody has since closed
         | is exactly the one that gets lost.
         */
        // A subquery off InboxMessage rather than a whereHas closure, so the
        // scope stays the single definition of "undelivered" and the builder
        // it is called on is the one that actually knows the scope.
        $undeliveredThreadIds = InboxMessage::query()
            ->undelivered()
            ->select('inbox_thread_id');

        $unsent = (clone $query)->whereIn('id', $undeliveredThreadIds)->count();

        if (! empty($validated['unsent'])) {
            $query->whereIn('id', $undeliveredThreadIds);
        }

        // Defaults to what still wants attention, which is what an inbox is
        // for. Everything else is a deliberate choice.
        $status = $validated['status'] ?? InboxStatus::Open->value;

        // The unsent view ignores status deliberately -- see above.
        if (empty($validated['unsent'])) {
            $query->where('status', $status);
        }

        if (isset($validated['brand'])
            && $brands->contains(fn ($b): bool => $b->getKey() === (int) $validated['brand'])) {
            $query->where('customer_id', (int) $validated['brand']);
        }

        if (! empty($validated['mine'])) {
            $query->where('assigned_to_user_id', $request->user()->getKey());
        }

        return view('agency.inbox.index', [
            'title' => 'Inbox',
            'threads' => $query->paginate(25)->withQueryString(),
            'brands' => $brands,
            'status' => $status,
            'statuses' => InboxStatus::cases(),
            'selectedBrand' => $validated['brand'] ?? null,
            'mine' => (bool) ($validated['mine'] ?? false),
            'unsent' => (bool) ($validated['unsent'] ?? false),
            'unsentCount' => $unsent,
        ]);
    }

    public function show(Request $request, InboxThread $thread): View
    {
        $request->user()->can('inbox.view') || abort(403);
        $this->assertReachable($request, $thread);

        return view('agency.inbox.show', [
            'title' => $thread->participant_name ?: 'Conversation',
            'thread' => $thread->load(['socialAccount', 'customer', 'assignee', 'target.post']),
            'messages' => $thread->messages()->get(),
            'statuses' => InboxStatus::cases(),
            'assignable' => $this->assignableMembers($request),
            'canReply' => $request->user()->can('inbox.reply'),
            /*
             | Asked before the reply box is drawn. Most platforms restrict
             | direct messages to a window after the person last wrote, and
             | letting somebody compose a careful answer only to be refused on
             | submit is the worst possible moment to tell them.
             */
            'replyable' => $this->canReplyTo($thread),
        ]);
    }

    public function reply(Request $request, InboxThread $thread): RedirectResponse
    {
        $request->user()->can('inbox.reply') || abort(403);
        $this->assertReachable($request, $thread);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'visibility' => ['required', 'string', 'in:public,internal'],
        ]);

        if ($validated['visibility'] === 'internal') {
            $this->replies->note($thread, $request->user(), $validated['body']);

            return back()->with('status', 'Note added.');
        }

        $message = $this->replies->reply($thread, $request->user(), $validated['body']);

        /*
         | The outcome is reported, not assumed. A reply the platform refused
         | is kept and shown as unsent, so somebody can retry rather than
         | believe a customer was answered.
         */
        return $message->delivery_status->isSettled()
            && $message->delivery_status->value === 'delivered'
                ? back()->with('status', 'Reply sent.')
                : back()->with('error', 'That reply was not accepted by the platform. It is kept here so you can try again.');
    }

    public function update(Request $request, InboxThread $thread): RedirectResponse
    {
        $request->user()->can('inbox.manage') || abort(403);
        $this->assertReachable($request, $thread);

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:open,pending,closed'],
            'assigned_to_user_id' => ['nullable', 'integer'],
        ]);

        $changes = [];

        if (isset($validated['status'])) {
            $changes['status'] = $validated['status'];
        }

        if (array_key_exists('assigned_to_user_id', $validated)) {
            $assignee = $validated['assigned_to_user_id'];

            /*
             | Only somebody who is actually on this team. An arbitrary user id
             | would otherwise assign a client's conversation to a stranger,
             | and the assignment list is the one place that is easy to forget.
             */
            if ($assignee !== null
                && ! $this->assignableMembers($request)->contains(
                    fn (User $u): bool => $u->getKey() === (int) $assignee,
                )) {
                abort(422);
            }

            $changes['assigned_to_user_id'] = $assignee;
        }

        if ($changes !== []) {
            $thread->forceFill($changes)->save();
        }

        return back()->with('status', 'Conversation updated.');
    }

    /**
     * Reachability, which is brand-scoped rather than merely tenant-scoped.
     *
     * 404 rather than 403, so an unreachable conversation and a missing one
     * are indistinguishable from outside.
     */
    private function assertReachable(Request $request, InboxThread $thread): void
    {
        abort_unless(
            $request->user()->canAccessCustomer($thread->customer_id),
            404,
        );
    }

    private function canReplyTo(InboxThread $thread): bool
    {
        try {
            $provider = $this->providers->for($thread->provider_key);
        } catch (UnknownProvider) {
            return false;
        }

        if (! $provider instanceof SupportsInbox) {
            return false;
        }

        return $provider->canReplyTo($thread->socialAccount, $thread->external_thread_id);
    }

    /** @return EloquentCollection<int, Customer> */
    private function visibleBrands(Request $request): EloquentCollection
    {
        $query = Customer::query()->orderBy('name');

        if (! $request->user()->can('customers.view_all')) {
            $query->whereIn('id', $request->user()->assignedCustomerIds());
        }

        return $query->get();
    }

    /** @return EloquentCollection<int, User> */
    private function assignableMembers(Request $request): EloquentCollection
    {
        return User::query()
            ->whereHas('tenants', fn ($q) => $q
                ->where('tenants.id', $this->context->id())
                ->where('tenant_user.status', 'active'))
            ->orderBy('name')
            ->get();
    }
}
