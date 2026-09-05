<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Engagement\DTO\FetchedMessage;
use App\Domain\Engagement\DTO\FetchedThread;
use App\Domain\Engagement\Enums\DeliveryStatus;
use App\Domain\Engagement\Enums\InboxKind;
use App\Domain\Engagement\Enums\InboxStatus;
use App\Domain\Engagement\Enums\MessageDirection;
use App\Domain\Engagement\Models\InboxMessage;
use App\Domain\Engagement\Models\InboxThread;
use App\Domain\Identity\Models\User;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | The unified inbox.
 |
 | Comments and messages from every connected account in one queue, because an
 | agency answering four networks in four browser tabs misses the one nobody
 | had open.
 */

beforeEach(function (): void {
    seedPermissions();
    FakeProvider::reset();
    app(ProviderRegistry::class)->register('fake', FakeProvider::class);

    $owner = User::factory()->create();
    $this->tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $this->owner = $owner->fresh();
    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $this->account = SocialAccount::factory()->forCustomer($this->brand)->create([
        'provider_key' => 'fake',
    ]);
});

/** A conversation as a provider would describe it. */
function fetchedThread(string $id = 'thread-1', string $body = 'Do you deliver?'): FetchedThread
{
    return new FetchedThread(
        externalThreadId: $id,
        kind: InboxKind::Comment,
        messages: [new FetchedMessage(
            externalMessageId: $id.'-m1',
            body: $body,
            direction: MessageDirection::Inbound,
            authorName: 'A Customer',
            postedAt: now()->subHour(),
        )],
        participantName: 'A Customer',
        lastMessageAt: now()->subHour(),
    );
}

// ---------------------------------------------------------------------- sync

it('brings a conversation in', function (): void {
    FakeProvider::willReturnThreads([fetchedThread()]);

    $this->artisan('inbox:sync')->assertSuccessful();

    $thread = InboxThread::query()->sole();

    expect($thread->external_thread_id)->toBe('thread-1')
        ->and($thread->status)->toBe(InboxStatus::Open)
        ->and($thread->messages()->count())->toBe(1);
});

it('does not duplicate a conversation on a second sync', function (): void {
    /*
     | Syncing against somebody else's API means pages arrive twice and runs
     | overlap. Everything keys on the provider's own ids so a second pass
     | changes nothing.
     */
    FakeProvider::willReturnThreads([fetchedThread()]);

    $this->artisan('inbox:sync')->assertSuccessful();
    $this->artisan('inbox:sync')->assertSuccessful();

    expect(InboxThread::query()->count())->toBe(1)
        ->and(InboxMessage::query()->count())->toBe(1);
});

it('never overwrites the agency own state', function (): void {
    /*
     | Status and assignment belong to the agency, not the provider. A sync
     | that reopened a closed thread or dropped the colleague who owns it --
     | every few minutes -- would make the queue unusable.
     */
    FakeProvider::willReturnThreads([fetchedThread()]);
    $this->artisan('inbox:sync')->assertSuccessful();

    $thread = InboxThread::query()->sole();
    $thread->forceFill([
        'status' => InboxStatus::Pending->value,
        'assigned_to_user_id' => $this->owner->getKey(),
    ])->save();

    $this->artisan('inbox:sync')->assertSuccessful();

    expect($thread->fresh()->status)->toBe(InboxStatus::Pending)
        ->and($thread->fresh()->assigned_to_user_id)->toBe($this->owner->getKey());
});

it('reopens a closed thread when somebody writes again', function (): void {
    // The deliberate exception. Somebody writing again is a new conversation
    // in every sense that matters, and leaving it closed ignores them twice.
    FakeProvider::willReturnThreads([fetchedThread()]);
    $this->artisan('inbox:sync')->assertSuccessful();

    $thread = InboxThread::query()->sole();
    $thread->forceFill(['status' => InboxStatus::Closed->value])->save();

    $again = fetchedThread();
    $again->messages[] = new FetchedMessage(
        externalMessageId: 'thread-1-m2',
        body: 'Still waiting.',
        direction: MessageDirection::Inbound,
        authorName: 'A Customer',
        postedAt: now(),
    );
    FakeProvider::willReturnThreads([$again]);

    $this->artisan('inbox:sync')->assertSuccessful();

    expect($thread->fresh()->status)->toBe(InboxStatus::Open);
});

// -------------------------------------------------------------------- replying

it('sends a reply and records that it arrived', function (): void {
    FakeProvider::willReturnThreads([fetchedThread()]);
    $this->artisan('inbox:sync')->assertSuccessful();

    $thread = InboxThread::query()->sole();

    $this->actingAs($this->owner)
        ->post(route('agency.inbox.reply', $thread), [
            'body' => 'We deliver on Tuesdays.',
            'visibility' => 'public',
        ])
        ->assertRedirect();

    $reply = InboxMessage::query()
        ->where('direction', MessageDirection::Outbound->value)->sole();

    expect($reply->delivery_status)->toBe(DeliveryStatus::Delivered)
        // The external id is what makes it survive the next sync as one
        // message rather than reappearing as a second copy.
        ->and($reply->external_message_id)->not->toBeNull()
        ->and(FakeProvider::sentReplies()['thread-1'])->toBe('We deliver on Tuesdays.')
        // Answered, so the thread is waiting on them rather than on us.
        ->and($thread->fresh()->status)->toBe(InboxStatus::Pending);
});

it('keeps a refused reply and says it was not sent', function (): void {
    /*
     | Somebody spent time writing it. Showing it as unsent lets them retry;
     | discarding it means the customer is ignored and nobody notices.
     */
    FakeProvider::willReturnThreads([fetchedThread()]);
    $this->artisan('inbox:sync')->assertSuccessful();

    $thread = InboxThread::query()->sole();
    FakeProvider::willFailReplyWith(ProviderErrorClass::Permission);

    $this->actingAs($this->owner)
        ->post(route('agency.inbox.reply', $thread), [
            'body' => 'We deliver on Tuesdays.',
            'visibility' => 'public',
        ])
        ->assertSessionHas('error');

    $reply = InboxMessage::query()
        ->where('direction', MessageDirection::Outbound->value)->sole();

    expect($reply->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($reply->body)->toBe('We deliver on Tuesdays.')
        // NOT moved on: a failed reply must not make the thread look answered.
        ->and($thread->fresh()->status)->toBe(InboxStatus::Open);
});

it('keeps an internal note out of the provider', function (): void {
    FakeProvider::willReturnThreads([fetchedThread()]);
    $this->artisan('inbox:sync')->assertSuccessful();

    $thread = InboxThread::query()->sole();

    $this->actingAs($this->owner)
        ->post(route('agency.inbox.reply', $thread), [
            'body' => 'This one always haggles.',
            'visibility' => 'internal',
        ]);

    $note = InboxMessage::query()->where('is_internal', true)->sole();

    expect($note->delivery_status)->toBe(DeliveryStatus::Delivered)
        ->and($note->external_message_id)->toBeNull()
        // Never handed to the platform.
        ->and(FakeProvider::sentReplies())->toBe([]);
});

// -------------------------------------------------------------- who may do what

it('lets a reader see the queue but not answer it', function (): void {
    // Answering speaks to a client's customer in the agency's name.
    $analyst = memberWithRole($this->tenant, 'Analyst');

    $this->actingAs($analyst)
        ->get(route('agency.inbox.index'))
        ->assertForbidden();
});

it('refuses to assign somebody who is not on the team', function (): void {
    /*
     | An arbitrary user id would hand a client's conversation to a stranger,
     | and the assignment list is the easy thing to forget to check.
     */
    FakeProvider::willReturnThreads([fetchedThread()]);
    $this->artisan('inbox:sync')->assertSuccessful();

    $outsider = User::factory()->create();

    $this->actingAs($this->owner)
        ->put(route('agency.inbox.update', InboxThread::query()->sole()), [
            'assigned_to_user_id' => $outsider->getKey(),
        ])
        ->assertStatus(422);
});

it('never shows another agency conversations', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    $rivalBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);
    $rivalAccount = SocialAccount::factory()->forCustomer($rivalBrand)->create([
        'provider_key' => 'fake',
    ]);

    InboxThread::factory()->forAccount($rivalAccount)->create([
        'participant_name' => 'Somebody Else',
    ]);

    actingForTenant($this->tenant);

    $this->actingAs($this->owner)
        ->get(route('agency.inbox.index'))
        ->assertOk()
        ->assertDontSee('Somebody Else');
});

// ------------------------------------------------- replies that never landed

/** A thread carrying one outbound message in a given delivery state. */
function threadWithReply(DeliveryStatus $status, array $threadOverrides = []): InboxThread
{
    $thread = InboxThread::factory()
        ->forAccount(test()->account)
        ->create($threadOverrides);

    InboxMessage::factory()->inThread($thread)->create([
        'direction' => MessageDirection::Outbound->value,
        'delivery_status' => $status->value,
    ]);

    return $thread;
}

it('tells the agency when a reply never reached the network', function (): void {
    /*
     | A failed reply was marked as such inside its own thread and nowhere
     | else, so the only way to find one was to open the thread you had no
     | reason to open. An agency believing it answered a customer it did not
     | answer is worse than never having replied: nobody is waiting.
     */
    threadWithReply(DeliveryStatus::Failed);

    asAgencyUser($this->owner)
        ->get(route('agency.inbox.index'))
        ->assertOk()
        ->assertSee('One reply never reached the network.');
});

it('finds an unsent reply on a thread that has been closed', function (): void {
    // A failed reply on a thread somebody has since closed is exactly the one
    // that gets lost, so this view ignores the status filter deliberately.
    $thread = threadWithReply(DeliveryStatus::Failed, ['status' => InboxStatus::Closed->value]);

    asAgencyUser($this->owner)
        ->get(route('agency.inbox.index', ['unsent' => 1]))
        ->assertOk()
        ->assertSee($thread->participant_name);
});

it('says nothing when every reply landed', function (): void {
    threadWithReply(DeliveryStatus::Delivered);

    asAgencyUser($this->owner)
        ->get(route('agency.inbox.index'))
        ->assertOk()
        ->assertDontSee('never reached the network');
});

it('does not count another agency unsent replies', function (): void {
    [$rival] = provisionTenant('Rival Agency');
    actingForTenant($rival);

    $rivalBrand = Customer::factory()->create(['tenant_id' => $rival->getKey()]);
    $rivalAccount = SocialAccount::factory()->forCustomer($rivalBrand)->create();
    $rivalThread = InboxThread::factory()->forAccount($rivalAccount)->create();

    InboxMessage::factory()->inThread($rivalThread)->create([
        'direction' => MessageDirection::Outbound->value,
        'delivery_status' => DeliveryStatus::Failed->value,
    ]);

    actingForTenant($this->tenant);

    asAgencyUser($this->owner)
        ->get(route('agency.inbox.index'))
        ->assertOk()
        ->assertDontSee('never reached the network');
});
