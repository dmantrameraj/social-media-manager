<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Customers\Services\PortalPostQuery;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Exceptions\UnauthorizedTransition;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostComment;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    seedPermissions();

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
    ]);

    $this->otherBrand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Ironworks Gym',
    ]);

    $this->client = portalUser($this->tenant, $this->brand, PortalRole::Approver);
});

/** A portal user assigned to one brand with a given role. */
function portalUser(Tenant $tenant, Customer $brand, PortalRole $role): CustomerPortalUser
{
    $user = CustomerPortalUser::factory()->forTenant($tenant)->create([
        'password' => bcrypt('client-password-12345'),
    ]);

    $brand->portalUsers()->attach($user->getKey(), ['role' => $role->value]);

    return $user->fresh();
}

function postFor(Customer $brand, PostStatus $status): Post
{
    return Post::factory()->forCustomer($brand)->status($status)->create();
}

/** Sign in on the customer guard. */
function asClient(CustomerPortalUser $user)
{
    return test()->actingAs($user, 'customer');
}

// ------------------------------------------------------------------ sign in

it('signs a client in and lands them somewhere that renders', function (): void {
    $this->post(route('portal.login.store'), [
        'email' => $this->client->email,
        'password' => 'client-password-12345',
    ])->assertRedirect(route('portal.dashboard', absolute: false));

    expect(auth('customer')->id())->toBe($this->client->getKey());

    $this->get(route('portal.dashboard'))->assertOk();
});

it('gives the same message whichever way a sign-in fails', function (): void {
    // Distinguishing "no such account" from "wrong password" tells an attacker
    // which client emails exist on the platform.
    $unknown = $this->post(route('portal.login.store'), [
        'email' => 'nobody@example.com',
        'password' => 'client-password-12345',
    ]);

    $wrongPassword = $this->post(route('portal.login.store'), [
        'email' => $this->client->email,
        'password' => 'not-the-password',
    ]);

    $message = 'These credentials do not match our records.';

    $unknown->assertSessionHasErrors(['email' => $message]);
    $wrongPassword->assertSessionHasErrors(['email' => $message]);
});

it('never signs a client in on the agency guard', function (): void {
    $this->post(route('portal.login.store'), [
        'email' => $this->client->email,
        'password' => 'client-password-12345',
    ]);

    expect(auth('web')->check())->toBeFalse(
        'A portal login established an agency session.',
    );
});

// ------------------------------------------------------- surface separation

it('keeps a portal user out of every agency and admin route', function (): void {
    /*
     | The Phase 1 gate from docs/04-AUTH-RBAC.md section 10. Iterated over the
     | route table rather than spot-checked, so a route added later is covered
     | by default.
     */
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'agency.') && ! str_starts_with($name, 'admin.')) {
            continue;
        }

        $uri = '/'.ltrim(str_replace(
            ['{brand}', '{post}', '{tenant}', '{user}', '{key}', '{uuid}'],
            [$this->brand->getRouteKey(), '1', '1', '1', 'brands.max', 'x'],
            $route->uri(),
        ), '/');

        $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

        $response = asClient($this->client)->call($method, $uri);

        expect($response->status())->not->toBeIn(
            [200, 201],
            "[{$name}] served a portal user on the agency surface.",
        );

        $checked++;
    }

    expect($checked)->toBeGreaterThan(10);
});

it('cannot reach the portal at all without signing in', function (): void {
    $this->get(route('portal.dashboard'))->assertRedirect();
    $this->get(route('portal.posts.index'))->assertRedirect();
});

// -------------------------------------------------------------- visibility

it('hides everything below client review', function (): void {
    $draft = postFor($this->brand, PostStatus::Draft);
    $internal = postFor($this->brand, PostStatus::InternalReview);
    $ready = postFor($this->brand, PostStatus::ClientReview);

    $body = asClient($this->client)->get(route('portal.posts.index'))->assertOk()->getContent();

    expect(str_contains($body, $ready->title))->toBeTrue('A post at client review was hidden.');

    foreach ([$draft, $internal] as $hidden) {
        expect(str_contains($body, $hidden->title))->toBeFalse(
            "A post at {$hidden->status->value} was shown to the client.",
        );
    }
});

it('answers 404 rather than 403 for a post the client may not see', function (): void {
    // 403 would confirm the post exists. A client must not be able to learn
    // what an agency is working on by probing ids.
    $draft = postFor($this->brand, PostStatus::Draft);
    $otherBrand = postFor($this->otherBrand, PostStatus::ClientReview);

    asClient($this->client)->get(route('portal.posts.show', $draft))->assertNotFound();
    asClient($this->client)->get(route('portal.posts.show', $otherBrand))->assertNotFound();
});

it('shows nothing at all from another tenant', function (): void {
    $otherOwner = User::factory()->create();
    $otherTenant = app(ProvisionTenantService::class)->execute($otherOwner, 'Rival Agency');

    app(TenantContext::class)->set($otherTenant);
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    $foreignPost = postFor($foreignBrand, PostStatus::ClientReview);
    actingForTenant($this->tenant);

    asClient($this->client)->get(route('portal.posts.show', $foreignPost))->assertNotFound();

    $body = asClient($this->client)->get(route('portal.posts.index'))->assertOk()->getContent();
    expect(str_contains($body, $foreignPost->title))->toBeFalse();
});

it('never shows an internal comment', function (): void {
    $post = postFor($this->brand, PostStatus::ClientReview);

    PostComment::query()->forceCreate([
        'tenant_id' => $this->tenant->getKey(),
        'post_id' => $post->getKey(),
        'author_type' => ActorType::User->value,
        'author_id' => $this->owner->getKey(),
        'body' => 'INTERNAL-ONLY the client is difficult about pricing',
        'is_internal' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    PostComment::query()->forceCreate([
        'tenant_id' => $this->tenant->getKey(),
        'post_id' => $post->getKey(),
        'author_type' => ActorType::User->value,
        'author_id' => $this->owner->getKey(),
        'body' => 'Here is the draft for your review',
        'is_internal' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $body = asClient($this->client)->get(route('portal.posts.show', $post))->assertOk()->getContent();

    expect(str_contains($body, 'INTERNAL-ONLY'))->toBeFalse('An internal comment reached the client.');
    expect(str_contains($body, 'Here is the draft'))->toBeTrue();
});

// --------------------------------------------------------------- decisions

it('lets an approver approve, and records the decision', function (): void {
    $post = postFor($this->brand, PostStatus::ClientReview);

    asClient($this->client)
        ->post(route('portal.posts.approve', $post), ['comment' => 'Looks great.'])
        ->assertRedirect();

    expect($post->fresh()->status)->toBe(PostStatus::ClientApproved);

    $approval = DB::table('post_approvals')->where('post_id', $post->getKey())->latest('id')->first();

    expect($approval)->not->toBeNull()
        ->and($approval->stage)->toBe('client')
        ->and($approval->action)->toBe('approved')
        ->and($approval->actor_id)->toBe($this->client->getKey());
});

it('lets an approver send a post back for changes', function (): void {
    $post = postFor($this->brand, PostStatus::ClientReview);

    asClient($this->client)
        ->post(route('portal.posts.changes', $post), ['comment' => 'Can we soften the headline?'])
        ->assertRedirect();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('refuses a viewer the ability to approve', function (): void {
    /*
     | Regression, and the reason PostStatusMachine now checks portal actors:
     | assertCan() tested `$actor instanceof User`, so a CustomerPortalUser fell
     | straight through every permission check and could make any transition the
     | machine considered legal -- including approving a client's content with
     | view-only access.
     */
    $viewer = portalUser($this->tenant, $this->brand, PortalRole::Viewer);
    $post = postFor($this->brand, PostStatus::ClientReview);

    asClient($viewer)
        ->post(route('portal.posts.approve', $post), ['comment' => 'I should not be able to do this.'])
        ->assertRedirect();

    expect($post->fresh()->status)->toBe(
        PostStatus::ClientReview,
        'A view-only client approved a post.',
    );
});

it('refuses a client any transition that is not theirs to make', function (): void {
    // Scheduling, cancelling and publishing are agency decisions. The machine
    // considers scheduled a legal move from client_approved, so only the portal
    // allow-list stops it.
    $post = postFor($this->brand, PostStatus::ClientApproved);

    $machine = app(PostStatusMachine::class);

    expect(fn () => $machine->transition($post, PostStatus::Scheduled, $this->client))
        ->toThrow(UnauthorizedTransition::class);

    expect($post->fresh()->status)->toBe(PostStatus::ClientApproved);
});

it('refuses a decision on a post that is not awaiting one', function (): void {
    // Authorisation and workflow state are checked together, so approving an
    // already-published post is refused rather than silently re-run.
    $post = postFor($this->brand, PostStatus::Published);

    asClient($this->client)
        ->post(route('portal.posts.approve', $post))
        ->assertRedirect();

    expect($post->fresh()->status)->toBe(PostStatus::Published);
});

it('refuses a decision on another brand entirely', function (): void {
    $post = postFor($this->otherBrand, PostStatus::ClientReview);

    asClient($this->client)->post(route('portal.posts.approve', $post))->assertNotFound();

    expect($post->fresh()->status)->toBe(PostStatus::ClientReview);
});

// ---------------------------------------------------------------- comments

it('lets a client comment, and never marks it internal', function (): void {
    $post = postFor($this->brand, PostStatus::ClientReview);

    asClient($this->client)
        ->post(route('portal.posts.comment', $post), ['body' => 'Could we use the other photo?'])
        ->assertRedirect();

    $comment = PostComment::query()->where('post_id', $post->getKey())->latest('id')->first();

    expect($comment)->not->toBeNull()
        ->and($comment->is_internal)->toBeFalse()
        // The short discriminator, matching audit_logs and post_approvals --
        // author_type is varchar(40) and the FQCN does not fit.
        ->and($comment->author_type)->toBe(ActorType::CustomerPortalUser->value)
        ->and($comment->authorLabel())->toBe('Client');
});

it('ignores an attempt to post a hidden comment', function (): void {
    // is_internal is hardcoded, never read from input: a client comment created
    // with the flag set would be invisible to the agency it was written for.
    $post = postFor($this->brand, PostStatus::ClientReview);

    asClient($this->client)->post(route('portal.posts.comment', $post), [
        'body' => 'Please do not hide this',
        'is_internal' => true,
    ]);

    expect(PostComment::query()->where('post_id', $post->getKey())->first()->is_internal)
        ->toBeFalse();
});

it('refuses a comment on a post the client cannot see', function (): void {
    $draft = postFor($this->brand, PostStatus::Draft);

    asClient($this->client)
        ->post(route('portal.posts.comment', $draft), ['body' => 'How did I get here?'])
        ->assertNotFound();

    expect(PostComment::query()->where('post_id', $draft->getKey())->count())->toBe(0);
});

// ------------------------------------------------------------- the boundary

it('defines client-visible statuses as an allow-list', function (): void {
    // Not "anything at or past client_review": enum ordering is not a security
    // boundary, and a status inserted in the middle later must not silently
    // become visible.
    $hidden = [
        PostStatus::Idea, PostStatus::Draft, PostStatus::InternalReview,
        PostStatus::ManagerApproved, PostStatus::Cancelled, PostStatus::Paused,
        PostStatus::Failed,
    ];

    foreach ($hidden as $status) {
        expect(in_array($status, PortalPostQuery::VISIBLE_STATUSES, true))->toBeFalse(
            "[{$status->value}] is visible to clients.",
        );
    }
});

it('shows a client with no brand assignment nothing at all', function (): void {
    $orphan = CustomerPortalUser::factory()->forTenant($this->tenant)->create();
    postFor($this->brand, PostStatus::ClientReview);

    expect(app(PortalPostQuery::class)->for($orphan->fresh())->count())->toBe(0);
});
