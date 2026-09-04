<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Customers\Models\Customer;
use App\Domain\Media\Models\Media;
use App\Domain\Media\Services\SignedMediaUrl;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Exceptions\CannotReschedule;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostComment;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Services\ReschedulePostService;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Social\Models\SocialAccount;
use App\Http\Requests\Agency\StorePostRequest;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The unified composer.
 *
 * One master post plus a target per selected account, exactly as the engine
 * expects -- so what the composer writes is what the publisher consumes, with
 * no translation layer between them.
 */
final class PostController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PostStatusMachine $machine,
    ) {}

    public function index(Request $request): View
    {
        $request->user()->can('posts.view') || abort(403);

        return view('agency.posts.index', [
            'title' => 'Content',
            'posts' => Post::query()
                ->whereIn('customer_id', $this->visibleBrandIds($request))
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function create(Request $request): View
    {
        $request->user()->can('posts.create') || abort(403);

        $brands = $this->visibleBrands($request);

        return view('agency.posts.create', [
            'title' => 'Create post',
            'brands' => $brands,
            // Only publishable destinations are offered. An account behind an
            // expired connection would fail at publish time, so it is not
            // presented as a choice.
            'accounts' => SocialAccount::query()
                ->publishable()
                ->whereIn('customer_id', $brands->pluck('id'))
                ->orderBy('name')
                ->get(),

            /*
             | Only usable files are offered. A still-processing or failed
             | upload presented as a choice would be attached and then fail at
             | publish time, which is the worst possible moment to find out.
             |
             | Grouped by brand in the view, so switching brand does not offer
             | another client's library.
             */
            'media' => Media::query()
                ->ready()
                ->whereIn('customer_id', $brands->pluck('id'))
                ->orderByDesc('id')
                ->limit(200)
                ->get(),
        ]);
    }

    public function store(StorePostRequest $request): RedirectResponse
    {
        $request->user()->can('posts.create') || abort(403);

        $brand = Customer::query()->findOrFail($request->integer('customer_id'));

        $request->user()->can('view', $brand) || abort(403);

        $accounts = $this->resolveAccounts($request->input('accounts', []), $brand);
        $media = $this->resolveMedia($request->input('media', []), $brand);

        $post = DB::transaction(function () use ($request, $brand, $accounts, $media): Post {
            $post = new Post;
            $post->tenant_id = $brand->tenant_id;
            $post->customer_id = $brand->getKey();
            $post->created_by_user_id = $request->user()->getKey();
            $post->title = $request->input('title');
            $post->body = (string) $request->input('body');
            /*
             | Derived from what is attached, not assumed. Providers branch on
             | this -- a video post and an image post are different API calls on
             | every network -- so hardcoding 'text' would mislabel every post
             | that carries media.
             */
            $post->content_type = match (true) {
                $media->contains(fn ($item): bool => $item->isVideo()) => 'video',
                $media->isNotEmpty() => 'image',
                default => 'text',
            };
            $post->status = PostStatus::Draft;
            $post->source = 'manual';
            $post->approval_required = $brand->requiresClientApproval();

            // Timezone is snapshotted from the brand, and the entered time is
            // interpreted in it before being stored as UTC. Storing local time
            // is how scheduled posts go out an hour early twice a year.
            $post->timezone = $brand->effectiveTimezone();

            if ($request->filled('scheduled_at')) {
                $post->scheduled_at = Carbon::parse(
                    (string) $request->input('scheduled_at'),
                    $post->timezone,
                )->utc();
            }

            $post->save();

            foreach ($accounts as $account) {
                $target = new PostTarget;
                $target->tenant_id = $post->tenant_id;
                $target->post_id = $post->getKey();
                $target->social_account_id = $account->getKey();
                $target->provider_key = $account->provider_key;
                $target->scheduled_at = $post->scheduled_at;
                $target->max_attempts = (int) config('publishing.max_attempts', 3);
                // Stable per target, and the anchor the engine retries against.
                $target->idempotency_key = hash('sha256', $post->getKey().':'.$account->getKey().':'.Str::ulid());
                $target->save();
            }

            /*
             | Order is the submitted order, not the library's. A carousel is a
             | sequence the author arranged deliberately, and sort_order is the
             | only record of that intent -- the portal and every provider read
             | it back.
             */
            foreach ($media->values() as $index => $item) {
                DB::table('post_media')->insert([
                    'tenant_id' => $post->tenant_id,
                    'post_id' => $post->getKey(),
                    'media_id' => $item->getKey(),
                    'sort_order' => $index,
                    'role' => 'primary',
                ]);
            }

            return $post;
        });

        return redirect()
            ->route('agency.posts.show', $post)
            ->with('status', 'Draft saved.');
    }

    public function show(Request $request, Post $post, SignedMediaUrl $urls): View
    {
        $request->user()->can('posts.view') || abort(403);
        $this->assertReachable($request, $post);

        return view('agency.posts.show', [
            'title' => $post->title ?: 'Post',
            'post' => $post->load('targets.socialAccount', 'approvals', 'media'),

            /*
             | Both halves. The agency sees internal notes AND what the client
             | wrote -- the client's side was previously written into a table
             | nothing on this surface read.
             */
            'comments' => PostComment::query()
                ->where('post_id', $post->getKey())
                ->orderBy('created_at')
                ->get(),
            'canReplyToClient' => $request->user()->can('posts.update'),

            // Signed URLs for the images, so staff can see what the client will
            // see rather than trusting a filename.
            'previews' => $post->media
                ->filter(fn (Media $item): bool => $item->isImage() && $item->isUsable())
                ->mapWithKeys(fn (Media $item) => [$item->getKey() => $urls->forAgency($item)]),
            // The composer never sets status directly; it asks the machine
            // what is legal from here.
            'allowedTransitions' => $this->machine->allowedFrom($post->status),
        ]);
    }

    public function transition(Request $request, Post $post): RedirectResponse
    {
        $this->assertReachable($request, $post);

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $to = PostStatus::tryFrom($validated['status']);

        if ($to === null) {
            return back()->with('error', 'That is not a valid status.');
        }

        try {
            // Legality AND permission are both enforced inside the machine.
            $this->machine->transition($post, $to, $request->user(), $validated['comment'] ?? null);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Post updated.');
    }

    /**
     * Move a scheduled post, from the calendar or from the post screen.
     *
     * A drag-and-drop is a request like any other. The browser is told what it
     * may do only so the UI does not offer the impossible; everything that
     * decides whether the move HAPPENS -- permission, tenancy, brand access,
     * post state, targets in flight, lead time -- is decided again here.
     */
    public function reschedule(
        Request $request,
        Post $post,
        ReschedulePostService $scheduler,
    ): RedirectResponse|JsonResponse {
        $request->user()->can('posts.schedule') || abort(403);
        $this->assertReachable($request, $post);

        $validated = $request->validate([
            /*
             | Two shapes, because a drag and a form are different gestures.
             | Dropping a post on the 14th says WHICH DAY and nothing about the
             | minute, so `date` keeps the time the author already chose. The
             | post screen sends a full `scheduled_at` and sets both.
             */
            'date' => ['required_without:scheduled_at', 'nullable', 'date_format:Y-m-d'],
            'scheduled_at' => ['required_without:date', 'nullable', 'date'],
        ]);

        $wallClock = isset($validated['scheduled_at'])
            ? (string) $validated['scheduled_at']
            : $validated['date'].' '.$this->timeOfDay($post);

        try {
            $scheduler->execute($post, $scheduler->resolve($post, $wallClock), $request->user());
        } catch (CannotReschedule $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->with('error', $e->getMessage());
        }

        $post->refresh();

        $when = $post->scheduled_at
            ->copy()
            ->setTimezone($post->timezone ?: config('app.timezone'))
            ->format('j M Y, H:i');

        return $request->expectsJson()
            ? response()->json(['message' => 'Moved to '.$when.'.', 'scheduled_at' => $post->scheduled_at->toIso8601String()])
            : back()->with('status', 'Moved to '.$when.'.');
    }

    /**
     * The time of day a dragged post keeps.
     *
     * Read in the post's own timezone. Taking it from the UTC column would
     * move an Asia/Kolkata post's 09:00 to 03:30 the moment somebody dragged
     * it to another day, which is not what dragging a post means.
     */
    private function timeOfDay(Post $post): string
    {
        if ($post->scheduled_at === null) {
            return (string) config('publishing.default_schedule_time', '09:00');
        }

        return $post->scheduled_at
            ->copy()
            ->setTimezone($post->timezone ?: config('app.timezone'))
            ->format('H:i');
    }

    public function calendar(Request $request): View
    {
        $request->user()->can('posts.view') || abort(403);

        $month = Carbon::parse(
            $request->string('month')->toString() ?: now()->format('Y-m').'-01'
        )->startOfMonth();

        /*
         | Bounded by date range and by visible brands. An unbounded month
         | query is a guaranteed incident once an agency has thousands of
         | posts.
         */
        $scheduled = Post::query()
            ->whereIn('customer_id', $this->visibleBrandIds($request))
            ->whereNotNull('scheduled_at')
            /*
             | A day wider at each end than the month being shown.
             |
             | scheduled_at is UTC and the grid is drawn in the brand's zone, so
             | the two disagree at the edges: a post at 02:00 on 1 October in
             | Asia/Kolkata is stored as 20:30 on 30 September. Querying the
             | month exactly would drop it from October and show it in
             | September. No timezone is more than a day out, so a day of slack
             | covers every zone, and the grouping below decides the real day.
             */
            ->whereBetween('scheduled_at', [
                $month->copy()->startOfMonth()->subDay(),
                $month->copy()->endOfMonth()->addDay(),
            ])
            ->orderBy('scheduled_at')
            ->get();

        /*
         | Which of these may be dragged, decided in ONE query for the month
         | rather than one per post. A calendar that asked per row would issue
         | a hundred queries to draw a busy month.
         |
         | This only governs the draggable attribute. The endpoint checks the
         | same things again, because anything the browser is told is a
         | suggestion.
         */
        $inFlight = PostTarget::query()
            ->whereIn('post_id', $scheduled->modelKeys())
            ->where('status', TargetStatus::Processing->value)
            ->pluck('post_id')
            ->all();

        $movable = $scheduled
            ->filter(fn (Post $post): bool => ReschedulePostService::statusPermitsMove($post->status)
                && ! in_array($post->getKey(), $inFlight, true))
            ->modelKeys();

        return view('agency.posts.calendar', [
            'title' => 'Calendar',
            'month' => $month,
            'posts' => $scheduled->groupBy(fn (Post $post): string => $post->scheduled_at
                ->copy()
                ->setTimezone($post->timezone ?: config('app.timezone'))
                ->toDateString()),
            'movable' => $movable,
        ]);
    }

    /**
     * Accounts must belong to the chosen brand. Ids arrive from a form, and
     * the global scope cannot see intent -- without this a crafted payload
     * could target another brand's account.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, SocialAccount>
     */
    private function resolveAccounts(array $ids, Customer $brand)
    {
        if ($ids === []) {
            return collect();
        }

        return SocialAccount::query()
            ->publishable()
            ->where('customer_id', $brand->getKey())
            ->whereIn('id', array_map('intval', $ids))
            ->get();
    }

    /**
     * Media the author may actually attach.
     *
     * Filtered to the CHOSEN BRAND and to usable files, then re-ordered to the
     * submitted sequence. Ids arrive from a form, so brand ownership is checked
     * here rather than trusted -- and `isUsable()` keeps a still-processing or
     * failed upload out of a post that is about to be scheduled.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, Media>
     */
    private function resolveMedia(array $ids, Customer $brand)
    {
        if ($ids === []) {
            return collect();
        }

        $ordered = array_values(array_unique(array_map('intval', $ids)));

        $media = Media::query()
            ->where('customer_id', $brand->getKey())
            ->whereIn('id', $ordered)
            ->get()
            ->filter(fn (Media $item): bool => $item->isUsable());

        // sortBy over the submitted order: whereIn returns rows in whatever
        // order the database likes, which is not the order the author chose.
        return $media->sortBy(
            fn (Media $item): int => (int) array_search($item->getKey(), $ordered, true),
        )->values();
    }

    private function assertReachable(Request $request, Post $post): void
    {
        abort_unless(
            $post->tenant_id === $this->context->id()
                && $request->user()->canAccessCustomer($post->customer_id),
            404,
        );
    }

    /** @return Collection<int, Customer> */
    private function visibleBrands(Request $request)
    {
        $query = Customer::query()->active()->orderBy('name');

        if (! $request->user()->can('customers.view_all')) {
            $query->whereIn('id', $request->user()->assignedCustomerIds());
        }

        return $query->get();
    }

    /** @return Collection<int, int> */
    private function visibleBrandIds(Request $request)
    {
        return $this->visibleBrands($request)->pluck('id');
    }
}
