<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Customers\Models\Customer;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Social\Models\SocialAccount;
use App\Http\Requests\Agency\StorePostRequest;
use App\Support\TenantContext;
use Illuminate\Contracts\View\View;
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
        ]);
    }

    public function store(StorePostRequest $request): RedirectResponse
    {
        $request->user()->can('posts.create') || abort(403);

        $brand = Customer::query()->findOrFail($request->integer('customer_id'));

        $request->user()->can('view', $brand) || abort(403);

        $accounts = $this->resolveAccounts($request->input('accounts', []), $brand);

        $post = DB::transaction(function () use ($request, $brand, $accounts): Post {
            $post = new Post;
            $post->tenant_id = $brand->tenant_id;
            $post->customer_id = $brand->getKey();
            $post->created_by_user_id = $request->user()->getKey();
            $post->title = $request->input('title');
            $post->body = (string) $request->input('body');
            $post->content_type = 'text';
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

            return $post;
        });

        return redirect()
            ->route('agency.posts.show', $post)
            ->with('status', 'Draft saved.');
    }

    public function show(Request $request, Post $post): View
    {
        $request->user()->can('posts.view') || abort(403);
        $this->assertReachable($request, $post);

        return view('agency.posts.show', [
            'title' => $post->title ?: 'Post',
            'post' => $post->load('targets.socialAccount', 'approvals'),
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
        $posts = Post::query()
            ->whereIn('customer_id', $this->visibleBrandIds($request))
            ->whereNotNull('scheduled_at')
            ->whereBetween('scheduled_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (Post $post): string => $post->scheduled_at->toDateString());

        return view('agency.posts.calendar', [
            'title' => 'Calendar',
            'month' => $month,
            'posts' => $posts,
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
