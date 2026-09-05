<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Media\Models\Media;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 | Editing a post.
 |
 | There was no way to do this. A post could be created and moved through the
 | workflow, and its words could never be changed -- which made Rejected a dead
 | end rather than the round trip the status machine describes. PostStatus::
 | isEditable() had been in the enum since Phase 3 with no caller at all.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->travelTo(Carbon::parse('2026-03-01 12:00:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
        'timezone' => 'Asia/Kolkata',
    ]);

    $this->account = SocialAccount::factory()->forCustomer($this->brand)->create();
});

function editablePost(PostStatus $status = PostStatus::Draft): Post
{
    return Post::factory()
        ->forCustomer(test()->brand)
        ->status($status)
        ->create(['title' => 'Before', 'body' => 'The old words.']);
}

it('changes the words', function (): void {
    $post = editablePost();

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), [
            'title' => 'After',
            'body' => 'The new words.',
        ])
        ->assertRedirect(route('agency.posts.show', $post));

    expect($post->refresh()->title)->toBe('After')
        ->and($post->body)->toBe('The new words.');
});

it('refuses to edit a post somebody has already approved', function (): void {
    /*
     | A manager or a client read those words and agreed to them. Changing the
     | text under the approval would make the approval a statement about a post
     | that no longer exists.
     */
    $post = editablePost(PostStatus::ClientApproved);

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), ['body' => 'Sneaky rewrite.'])
        ->assertSessionHas('error');

    expect($post->refresh()->body)->toBe('The old words.');
});

it('refuses to edit a published post', function (): void {
    $post = editablePost(PostStatus::Published);

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), ['body' => 'Too late.'])
        ->assertSessionHas('error');

    expect($post->refresh()->body)->toBe('The old words.');
});

it('returns a rejected post to draft when it is edited', function (): void {
    /*
     | The status machine's own map anticipates this -- "rejection returns to
     | draft on edit" -- and the only other move from Rejected is Cancelled, so
     | without it an author who addressed the rejection was stuck.
     */
    $post = editablePost(PostStatus::Rejected);

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), ['body' => 'Addressed the notes.']);

    expect($post->refresh()->status)->toBe(PostStatus::Draft)
        // Through the machine, so the recovery is in the approval trail rather
        // than a status that appears to have changed by itself.
        ->and($post->approvals()->where('to_status', 'draft')->exists())->toBeTrue();
});

it('adds a destination', function (): void {
    $post = editablePost();

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), [
            'body' => 'With a destination.',
            'accounts' => [$this->account->getKey()],
        ]);

    expect($post->refresh()->targets()->sole()->social_account_id)
        ->toBe($this->account->getKey());
});

it('removes a destination the author deselected', function (): void {
    $post = editablePost();

    PostTarget::factory()->targeting($post, $this->account)->create();

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), ['body' => 'No destinations now.']);

    expect($post->refresh()->targets()->count())->toBe(0);
});

it('keeps a destination that has already gone out', function (): void {
    /*
     | A published target is the record that content exists on a network.
     | Deleting it because a checkbox was cleared would lose the publication
     | history while the post itself stays on the feed.
     */
    $post = editablePost();

    PostTarget::factory()->targeting($post, $this->account)
        ->status(TargetStatus::Published)->create();

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), ['body' => 'Deselecting everything.']);

    expect($post->refresh()->targets()->count())->toBe(1);
});

it('will not target another brand account', function (): void {
    $other = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $theirs = SocialAccount::factory()->forCustomer($other)->create();

    $post = editablePost();

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), [
            'body' => 'Wrong destination.',
            'accounts' => [$theirs->getKey()],
        ]);

    // Ids come from a form, and the global scope cannot see intent: a crafted
    // payload must not aim one client's post at another client's feed.
    expect($post->refresh()->targets()->count())->toBe(0);
});

it('reorders media the way the author arranged it', function (): void {
    $post = editablePost();

    $files = collect(range(1, 3))->map(
        fn (): int => Media::factory()->forCustomer($this->brand)->create()->getKey(),
    );

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), [
            'body' => 'A carousel.',
            'media' => [$files[2], $files[0], $files[1]],
        ]);

    // sort_order is the only record of a deliberate sequence, and the portal
    // and every provider read it back.
    $order = DB::table('post_media')->where('post_id', $post->getKey())
        ->orderBy('sort_order')->pluck('media_id')->all();

    expect($order)->toBe([$files[2], $files[0], $files[1]]);
});

it('reads the schedule in the post timezone', function (): void {
    $post = editablePost();

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), [
            'body' => 'Timed.',
            'scheduled_at' => '2026-04-15 09:00',
        ]);

    expect($post->refresh()->scheduled_at->utc()->format('Y-m-d H:i'))
        ->toBe('2026-04-15 03:30');
});

it('needs the update permission', function (): void {
    $post = editablePost();

    asAgencyUser(memberWithRole($this->tenant, 'Designer'))
        ->put(route('agency.posts.update', $post), ['body' => 'Not mine to change.'])
        ->assertForbidden();
});

it('cannot edit another agency post', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);
    $rivalBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);
    $rivalPost = Post::factory()->forCustomer($rivalBrand)->status(PostStatus::Draft)->create();
    actingForTenant($this->tenant);

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $rivalPost), ['body' => 'Not yours.'])
        ->assertNotFound();
});

it('records the edit', function (): void {
    $post = editablePost();

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), ['body' => 'Audited.']);

    $entry = AuditLog::query()->where('action', 'post.updated')->sole();

    expect($entry->old_values['body'])->toBe('The old words.')
        ->and($entry->new_values['body'])->toBe('Audited.');
});

it('offers the edit screen only for an editable post', function (): void {
    asAgencyUser($this->owner)
        ->get(route('agency.posts.edit', editablePost()))
        ->assertOk();

    asAgencyUser($this->owner)
        ->get(route('agency.posts.edit', editablePost(PostStatus::Published)))
        ->assertRedirect();
});

// ------------------------------------------------------- what it used to say

it('keeps what the post used to say', function (): void {
    /*
     | An edit replaces words a manager or a client agreed to. Without a
     | version there is no way to answer "what did they approve?" three weeks
     | later, when the post on the feed and the post in the database no longer
     | match.
     */
    $post = editablePost();

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), ['body' => 'The new words.']);

    $version = $post->versions()->sole();

    expect($version->version)->toBe(1)
        ->and($version->body)->toBe('The old words.')
        ->and($version->meta['title'])->toBe('Before');
});

it('keeps only superseded states, never the current one', function (): void {
    $post = editablePost();

    asAgencyUser($this->owner)->put(route('agency.posts.update', $post), ['body' => 'Second.']);
    asAgencyUser($this->owner)->put(route('agency.posts.update', $post), ['body' => 'Third.']);

    // Current text lives on the post row, so the two can never disagree about
    // which is authoritative.
    expect($post->refresh()->body)->toBe('Third.')
        ->and($post->versions()->pluck('body')->all())->toBe(['Second.', 'The old words.']);
});

it('writes no version when the edit was refused', function (): void {
    $post = editablePost(PostStatus::Published);

    asAgencyUser($this->owner)
        ->put(route('agency.posts.update', $post), ['body' => 'Too late.']);

    expect($post->versions()->count())->toBe(0);
});

it('will not let history be rewritten', function (): void {
    $post = editablePost();

    asAgencyUser($this->owner)->put(route('agency.posts.update', $post), ['body' => 'Changed.']);

    // Append-only, like post_approvals and login_histories. History that can
    // be edited is not history.
    $post->versions()->sole()->update(['body' => 'Something else entirely.']);
})->throws(RuntimeException::class);
