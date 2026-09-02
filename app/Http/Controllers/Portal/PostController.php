<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Customers\Services\PortalPostQuery;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostComment;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalDecisionRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Everything a client can do: read what was sent to them, answer it, and talk
 * about it.
 *
 * There is no shared controller with the agency surface. That is a deliberate
 * cost -- some query shapes are similar -- because a shared controller is one
 * forgotten branch away from serving agency data to a client.
 */
final class PostController extends Controller
{
    public function __construct(
        private readonly PortalPostQuery $posts,
        private readonly PostStatusMachine $machine,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user('customer');

        $brandId = $request->integer('brand') ?: null;
        $status = PostStatus::tryFrom((string) $request->query('status', ''));

        $posts = $this->posts->for($user)
            // A brand filter arriving from a query string is still checked
            // against the assignment list; the base query would exclude a
            // foreign brand anyway, but relying on that alone means the check
            // lives somewhere a refactor can move.
            ->when(
                $brandId !== null && $user->canAccessCustomer($brandId),
                fn ($query) => $query->where('customer_id', $brandId),
            )
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->with('customer')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('portal.posts.index', [
            'title' => 'Content',
            'posts' => $posts,
            'brands' => $user->customers()->get(),
            'brandId' => $brandId,
            'status' => $status,
            'statuses' => PortalPostQuery::VISIBLE_STATUSES,
        ]);
    }

    public function show(Request $request, Post $post): View
    {
        $user = $request->user('customer');

        $this->assertVisible($request, $post);

        return view('portal.posts.show', [
            'title' => $post->title ?: 'Post',
            'post' => $post->load('customer', 'targets'),

            /*
             | clientVisible() is the boundary, applied in the QUERY. Agency
             | staff discuss a client's brief, budget and last round of changes
             | in internal comments; filtering those in the view instead would
             | be one refactor away from a leak.
             */
            'comments' => PostComment::query()
                ->where('post_id', $post->getKey())
                ->clientVisible()
                ->orderBy('created_at')
                ->get(),

            'canDecide' => $post->status === PostStatus::ClientReview
                && $user->canApproveFor($post->customer_id),

            // Shown so a viewer-only client understands why there are no
            // buttons, rather than assuming the page is broken.
            'isViewerOnly' => ! $user->canApproveFor($post->customer_id),
        ]);
    }

    public function approve(PortalDecisionRequest $request, Post $post): RedirectResponse
    {
        return $this->decide($request, $post, PostStatus::ClientApproved, 'Approved. The agency has been notified.');
    }

    public function reject(PortalDecisionRequest $request, Post $post): RedirectResponse
    {
        return $this->decide($request, $post, PostStatus::Rejected, 'Rejected. The agency has been notified.');
    }

    /**
     * "Request changes" -- the third answer, and the one clients actually want
     * most of the time. Returns the post to the agency as a draft rather than
     * rejecting it outright, which reads very differently to the person who
     * wrote it.
     */
    public function requestChanges(PortalDecisionRequest $request, Post $post): RedirectResponse
    {
        return $this->decide($request, $post, PostStatus::Draft, 'Sent back to the agency with your notes.');
    }

    public function comment(Request $request, Post $post): RedirectResponse
    {
        $this->assertVisible($request, $post);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $user = $request->user('customer');

        PostComment::query()->forceCreate([
            'tenant_id' => $post->tenant_id,
            'post_id' => $post->getKey(),
            /*
             | The ActorType discriminator, not $user::class: author_type is
             | varchar(40) and the FQCN is 45 characters, so storing the class
             | name truncated and then failed outright on a strict connection.
             | It also matches what audit_logs and post_approvals already store,
             | which is what makes the three trails joinable.
             */
            'author_type' => ActorType::CustomerPortalUser->value,
            'author_id' => $user->getKey(),
            'body' => $validated['body'],
            // Hardcoded, never from input. A client comment is by definition
            // not internal, and accepting this from a form would let a crafted
            // request hide a comment from the person it was written for.
            'is_internal' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('status', 'Comment added.');
    }

    private function decide(
        PortalDecisionRequest $request,
        Post $post,
        PostStatus $to,
        string $message,
    ): RedirectResponse {
        $this->assertVisible($request, $post);

        $user = $request->user('customer');

        try {
            /*
             | Both the brand-level approval right and the legality of the move
             | are enforced inside the machine, against this post's brand. The
             | controller does not re-implement either -- console and future API
             | paths would skip a controller-side check.
             */
            $this->machine->transition(
                $post,
                $to,
                $user,
                $request->validated()['comment'] ?? null,
                stage: 'client',
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }

    /**
     * 404, not 403.
     *
     * A client must not be able to learn that a post exists by probing ids. A
     * post belonging to another agency, another brand, or still in draft are
     * all indistinguishable from a post that was never there.
     */
    private function assertVisible(Request $request, Post $post): void
    {
        abort_unless(
            $this->posts->isVisible($request->user('customer'), $post),
            404,
        );
    }
}
