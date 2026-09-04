<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostComment;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | The conversation on a post, from the agency side.
 |
 | PostComment's own docblock calls is_internal "the whole point of the model":
 | agency staff discuss a post privately, the client sees only what was written
 | for them. Only the client half was reachable -- the portal could post a
 | comment, the agency had no route and the post screen never rendered the
 | thread, so a client could write on work awaiting their approval and nobody
 | at the agency would ever see it.
 */

beforeEach(function (): void {
    seedPermissions();

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $this->owner = $owner->fresh();
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->post = Post::factory()->forCustomer($this->brand)
        ->status(PostStatus::ClientReview)->create();
});

/** A comment as one side or the other would have left it. */
function commentOn(Post $post, bool $internal, string $body): PostComment
{
    return PostComment::query()->forceCreate([
        'tenant_id' => $post->tenant_id,
        'post_id' => $post->getKey(),
        'author_type' => $internal ? ActorType::User->value : ActorType::CustomerPortalUser->value,
        'author_id' => 1,
        'body' => $body,
        'is_internal' => $internal,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ------------------------------------------------------------------- reading

it('shows the agency both halves of the thread', function (): void {
    // The client's comment is the one that was invisible: written on work
    // awaiting approval, and seen by nobody.
    commentOn($this->post, false, 'Can we soften the opening line?');
    commentOn($this->post, true, 'Client always asks for this, budget an extra pass.');

    $this->actingAs($this->owner)
        ->get(route('agency.posts.show', $this->post))
        ->assertOk()
        ->assertSee('Can we soften the opening line?')
        ->assertSee('Client always asks for this, budget an extra pass.');
});

it('never shows an internal note to the client', function (): void {
    /*
     | The rule the whole model exists for. Filtered in the QUERY, not the
     | view: a template that decides what a client may read is one refactor
     | away from leaking it.
     */
    commentOn($this->post, true, 'Client always asks for this, budget an extra pass.');
    commentOn($this->post, false, 'Looks good to us.');

    $portalUser = CustomerPortalUser::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
    ]);
    $portalUser->customers()->attach($this->brand->getKey(), [
        'tenant_id' => $this->tenant->getKey(),
        'role' => 'approver',
    ]);

    $this->actingAs($portalUser, 'customer')
        ->get(route('portal.posts.show', $this->post))
        ->assertOk()
        ->assertSee('Looks good to us.')
        ->assertDontSee('budget an extra pass');
});

// ------------------------------------------------------------------- writing

it('lets a member leave an internal note', function (): void {
    $this->actingAs($this->owner)
        ->from(route('agency.posts.show', $this->post))
        ->post(route('agency.posts.comment', $this->post), [
            'body' => 'Waiting on the photographer before we re-send.',
            'visibility' => 'internal',
        ])
        ->assertRedirect(route('agency.posts.show', $this->post));

    $comment = PostComment::query()->sole();

    expect($comment->is_internal)->toBeTrue()
        // The discriminator, not the class name -- it is what audit_logs and
        // post_approvals store, which is what makes the trails joinable.
        ->and($comment->author_type)->toBe(ActorType::User->value);
});

it('lets a member reply to the client', function (): void {
    $this->actingAs($this->owner)
        ->post(route('agency.posts.comment', $this->post), [
            'body' => 'Softened it, have another look.',
            'visibility' => 'client',
        ]);

    expect(PostComment::query()->sole()->is_internal)->toBeFalse();
});

it('does not take is_internal from the request', function (): void {
    /*
     | Nothing on the model is fillable for exactly this reason. A client-facing
     | comment forced internal would hide it from the person it was written for;
     | the reverse would leak a private note to a client.
     */
    $this->actingAs($this->owner)
        ->post(route('agency.posts.comment', $this->post), [
            'body' => 'Softened it, have another look.',
            'visibility' => 'client',
            'is_internal' => true,
        ]);

    expect(PostComment::query()->sole()->is_internal)->toBeFalse();
});

it('rejects a visibility it does not recognise', function (): void {
    $this->actingAs($this->owner)
        ->post(route('agency.posts.comment', $this->post), [
            'body' => 'Anything at all.',
            'visibility' => 'secret',
        ])
        ->assertSessionHasErrors('visibility');

    expect(PostComment::query()->count())->toBe(0);
});

// --------------------------------------------------------------- who may write

it('lets a read-only member think out loud but not address the client', function (): void {
    /*
     | Talking to the client is a different act from noting something for
     | colleagues. A Designer can do the second; the first is for whoever can
     | change the post itself.
     */
    $designer = memberWithRole($this->tenant, 'Designer');

    /*
     | Assigned to the brand. Without this the Designer cannot reach the post
     | at all -- Designer has no customers.view_all, so assignment governs --
     | and the test would pass for the wrong reason, proving brand scoping
     | rather than the permission split it means to isolate.
     */
    $designer->customers()->attach($this->brand->getKey(), [
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->actingAs($designer)
        ->post(route('agency.posts.comment', $this->post), [
            'body' => 'The crop is off on the second image.',
            'visibility' => 'internal',
        ]);

    expect(PostComment::query()->count())->toBe(1);

    $this->actingAs($designer)
        ->post(route('agency.posts.comment', $this->post), [
            'body' => 'Hello client.',
            'visibility' => 'client',
        ])
        ->assertForbidden();

    expect(PostComment::query()->count())->toBe(1);
});

it('cannot comment on a brand the member is not assigned to', function (): void {
    /*
     | Brand-scoped, not merely tenant-scoped. A member assigned to one client
     | must not join the conversation on another's work just because both
     | belong to the same agency.
     */
    $other = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $post = Post::factory()->forCustomer($other)->status(PostStatus::ClientReview)->create();

    // Content Creator has customers.view but no view_all, so assignment governs.
    $member = memberWithRole($this->tenant, 'Content Creator');

    $this->actingAs($member)
        ->post(route('agency.posts.comment', $post), [
            'body' => 'Should not land.',
            'visibility' => 'internal',
        ])
        ->assertNotFound();

    expect(PostComment::query()->count())->toBe(0);
});

it('cannot comment on another agency post', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    $foreignBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);
    $foreignPost = Post::factory()->forCustomer($foreignBrand)
        ->status(PostStatus::ClientReview)->create();

    actingForTenant($this->tenant);

    $this->actingAs($this->owner)
        ->post(route('agency.posts.comment', $foreignPost), [
            'body' => 'Should not land.',
            'visibility' => 'internal',
        ])
        ->assertNotFound();

    expect(PostComment::query()->count())->toBe(0);
});
