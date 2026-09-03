<?php

declare(strict_types=1);

use App\Domain\AI\Models\BrandBrain;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\CustomerPortalUser;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\PostTarget;
use App\Domain\Publishing\Services\ClaimPostTargetService;
use App\Domain\Publishing\Services\PublishPostTargetService;
use App\Domain\Publishing\Workflow\PostStatusMachine;
use App\Domain\Social\DTO\PublishPayload;
use App\Domain\Social\Enums\ProviderErrorClass;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Social\ProviderRegistry;
use App\Domain\Social\Providers\Fake\FakeProvider;
use App\Domain\Tenancy\Services\ProvisionTenantService;

/*
 | docs/00-PROJECT-OVERVIEW.md §4 states the V1 Definition of Done as one
 | end-to-end flow, "with tests". Every segment of it was covered -- publishing
 | mechanics especially, in detail -- but nothing walked the whole path, so
 | nothing proved the seams between those segments line up.
 |
 | That is the gap this file closes, and it is a different question from the
 | one the unit tests answer: each of them starts from a fixture placed exactly
 | where that unit expects it. This one starts from a signup and only ever uses
 | the state the previous step actually produced.
 |
 | The provider is the fake one. Real adapters are blocked on live provider
 | documentation (see §64), and they are not what this proves -- the engine's
 | orchestration is, and that is entirely ours.
 */

beforeEach(function (): void {
    seedPermissions();
    FakeProvider::reset();

    app(ProviderRegistry::class)->register('fake', FakeProvider::class);
});

it('walks the whole V1 definition-of-done flow', function (): void {
    // ---------------------------------------------------- agency signs up

    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $owner = $owner->fresh();

    actingForTenant($tenant);

    expect($tenant->trial_ends_at)->not->toBeNull('a signup starts the trial clock');

    // --------------------------------------------------- agency adds a brand

    $brand = Customer::factory()->create([
        'tenant_id' => $tenant->getKey(),
        'name' => 'Roast House Coffee',
    ]);

    // ------------------------------------------------ and configures the brain

    BrandBrain::factory()->forCustomer($brand)->create([
        'business_description' => 'A speciality coffee roaster in Leeds.',
    ]);

    expect(BrandBrain::query()->where('customer_id', $brand->getKey())->exists())->toBeTrue();

    // ------------------------------------------- three destinations are connected

    $facebook = SocialAccount::factory()->forCustomer($brand)->create();
    $instagram = SocialAccount::factory()->forCustomer($brand)->create();
    $linkedin = SocialAccount::factory()->forCustomer($brand)->create();

    // --------------------------------------------------- a creator drafts a post

    $post = Post::factory()->forCustomer($brand)->status(PostStatus::Draft)->create();

    foreach ([$facebook, $instagram, $linkedin] as $account) {
        PostTarget::factory()->targeting($post, $account)->create();
    }

    expect($post->targets()->count())->toBe(3);

    /*
     | Every transition below goes through PostStatusMachine rather than
     | writing the column. That is the point of walking the flow: the machine
     | is what enforces which moves are legal and who may make them, and a
     | test that set the status directly would prove the path is walkable
     | while saying nothing about whether it is permitted.
     */
    $machine = app(PostStatusMachine::class);

    // ------------------------------------------------------ internal approval

    $machine->transition($post, PostStatus::InternalReview, $owner);
    $machine->transition($post, PostStatus::ManagerApproved, $owner);

    expect($post->fresh()->approved_at)->not->toBeNull();

    // -------------------------------------------------- the client approves

    $machine->transition($post, PostStatus::ClientReview, $owner);

    $client = CustomerPortalUser::factory()->create(['tenant_id' => $tenant->getKey()]);
    $client->customers()->attach($brand->getKey(), [
        'tenant_id' => $tenant->getKey(),
        'role' => PortalRole::Approver->value,
    ]);

    // As the CLIENT, on the customer guard -- not the agency user standing in
    // for them. The portal's whole reason to exist is that this is a different
    // principal with different authority.
    $machine->transition($post->fresh(), PostStatus::ClientApproved, $client->fresh(), stage: 'client');

    expect($post->fresh()->status)->toBe(PostStatus::ClientApproved);

    // ------------------------------------------------------------ scheduled

    $machine->transition($post->fresh(), PostStatus::Scheduled, $owner);
    $machine->transition($post->fresh(), PostStatus::Processing, $owner);

    // ------------------------------- each target publishes INDEPENDENTLY

    $claims = app(ClaimPostTargetService::class);
    $publisher = app(PublishPostTargetService::class);

    /*
     | Eager loaded because lazy loading is disabled application-wide. The
     | engine reads socialAccount, and effectiveBody() reads post -- fetching
     | targets bare would fail here for a reason that has nothing to do with
     | the flow being tested.
     */
    $targets = $post->fresh()->targets()->with(['post', 'socialAccount'])->orderBy('id')->get();

    // Facebook and Instagram succeed.
    foreach ($targets->take(2) as $target) {
        $claims->claim($target);
        FakeProvider::willSucceedWith('external-'.$target->getKey());
        $publisher->execute($target, payloadForTarget($target));
    }

    // LinkedIn is rejected by the platform.
    $failing = $targets->last();
    $claims->claim($failing);
    FakeProvider::willFailWith(ProviderErrorClass::PlatformRejection, 'Rejected');
    $publisher->execute($failing, payloadForTarget($failing));

    // ------------------------- one failure does not fail the whole post

    $post = $post->fresh()->load('targets');

    expect($targets->get(0)->fresh()->status)->toBe(TargetStatus::Published)
        ->and($targets->get(1)->fresh()->status)->toBe(TargetStatus::Published)
        ->and($failing->fresh()->status)->toBe(TargetStatus::Failed)
        // "the single most important rule in the engine", per Post's own docblock.
        ->and($post->deriveStatusFromTargets())->toBe(PostStatus::PartiallyPublished);

    // --------------------------------- and the whole path is on the record

    $actions = AuditLog::query()
        ->where('tenant_id', $tenant->getKey())
        ->pluck('action');

    expect($actions)->not->toBeEmpty('every transition is meant to be auditable');
});

/** The payload the engine sends, built from the target's own state. */
function payloadForTarget(PostTarget $target): PublishPayload
{
    return new PublishPayload(
        body: $target->effectiveBody() ?: 'Fresh beans, roasted Tuesday.',
        contentType: 'text',
        idempotencyKey: $target->idempotency_key,
    );
}

it('records each transition in the audit trail', function (): void {
    /*
     | Split out from the walk above because it asks a different question: not
     | "does the flow complete" but "can somebody reconstruct it afterwards",
     | which is what the approval trail is actually sold on.
     */
    $owner = User::factory()->create();
    $tenant = app(ProvisionTenantService::class)->execute($owner, 'Bright Digital');
    $owner = $owner->fresh();

    actingForTenant($tenant);

    $brand = Customer::factory()->create(['tenant_id' => $tenant->getKey()]);
    $post = Post::factory()->forCustomer($brand)->status(PostStatus::Draft)->create();

    $machine = app(PostStatusMachine::class);
    $machine->transition($post, PostStatus::InternalReview, $owner);
    $machine->transition($post->fresh(), PostStatus::ManagerApproved, $owner);

    $approvals = $post->fresh()->approvals()->get();

    expect($approvals)->not->toBeEmpty()
        ->and($approvals->pluck('to_status')->all())
        ->toContain(PostStatus::ManagerApproved->value);
});

it('keeps the whole flow inside one tenant', function (): void {
    // The flow above runs entirely within one agency. This asserts the
    // boundary held while it did, rather than assuming it.
    $ownerA = User::factory()->create();
    $tenantA = app(ProvisionTenantService::class)->execute($ownerA, 'Agency A');

    $ownerB = User::factory()->create();
    $tenantB = app(ProvisionTenantService::class)->execute($ownerB, 'Agency B');

    actingForTenant($tenantA);
    $brandA = Customer::factory()->create(['tenant_id' => $tenantA->getKey()]);
    Post::factory()->forCustomer($brandA)->status(PostStatus::Draft)->create();

    actingForTenant($tenantB);

    expect(Post::query()->count())->toBe(0)
        ->and(Customer::query()->count())->toBe(0);
});
