<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostComment;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The agency half of the conversation.
 *
 * PostComment carries `is_internal` and the model's own docblock calls it "the
 * whole point of the model" -- agency staff discuss a post privately, the
 * client sees only what was written for them. The portal could post a comment
 * and the agency could not: no route, and the post screen never rendered the
 * thread at all.
 *
 * So a client could leave a comment on work awaiting their approval and nobody
 * at the agency would ever see it. The half that was built was the half that
 * talks to people who are not paying for the product.
 */
final class PostCommentController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function store(Request $request, Post $post): RedirectResponse
    {
        // Reading a post is the floor for joining its conversation.
        $request->user()->can('posts.view') || abort(403);

        /*
         | The same reachability rule the post screen uses, not merely the
         | tenant scope. Access here is BRAND-scoped: a member assigned to one
         | client must not be able to join the conversation on another's work
         | just because both belong to the same agency.
         |
         | 404 rather than 403, so an unreachable post and a missing one are
         | indistinguishable from outside.
         */
        abort_unless(
            $post->tenant_id === $this->context->id()
                && $request->user()->canAccessCustomer($post->customer_id),
            404,
        );

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:2000'],
            'visibility' => ['required', 'string', 'in:internal,client'],
        ]);

        $internal = $validated['visibility'] === 'internal';

        /*
         | Talking to the client is a different act from leaving a note for
         | colleagues, so it needs more than read access. A Designer can think
         | out loud on a post; sending words to the client is for whoever can
         | change the post itself.
         */
        if (! $internal) {
            $request->user()->can('posts.update') || abort(403);
        }

        PostComment::query()->forceCreate([
            'tenant_id' => $post->tenant_id,
            'post_id' => $post->getKey(),
            /*
             | The ActorType discriminator rather than the class name: it is
             | what audit_logs and post_approvals already store, which is what
             | makes the three trails joinable, and the FQCN does not fit the
             | column.
             */
            'author_type' => ActorType::User->value,
            'author_id' => $request->user()->getKey(),
            'body' => $validated['body'],
            /*
             | Derived from a validated enum, never mass-assigned. Nothing on
             | this model is fillable precisely so a crafted request cannot set
             | is_internal -- posting a client-visible comment as internal
             | would hide it from the person it was written for, and the
             | reverse would leak a private note to a client.
             */
            'is_internal' => $internal,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with(
            'status',
            $internal ? 'Internal note added.' : 'Comment sent to the client.',
        );
    }
}
