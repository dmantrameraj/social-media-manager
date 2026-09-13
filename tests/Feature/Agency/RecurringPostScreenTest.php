<?php

declare(strict_types=1);

use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Models\Post;
use App\Domain\Publishing\Models\RecurringPostRule;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Models\SocialAccount;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Carbon;

/*
 | The screens behind recurring posts.
 |
 | The engine has its own tests in Publishing/RecurringPostsTest.php; what is
 | tested here is that a person can reach it -- the failure this repository
 | keeps producing is a mechanism that works perfectly and is wired to nothing.
 |
 | Gated on the existing posts.* permissions rather than new catalogue keys, so
 | the authorisation tests below are checking real role boundaries rather than
 | a key invented for this feature.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->travelTo(Carbon::parse('2026-03-02 08:00:00', 'UTC'));

    $this->owner = User::factory()->create(['name' => 'Dana Owner']);
    $this->tenant = app(ProvisionTenantService::class)->execute($this->owner, 'Bright Digital');
    $this->owner = $this->owner->fresh();

    actingForTenant($this->tenant);

    $this->brand = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Roast House',
        'timezone' => 'UTC',
        'settings' => ['approval_required' => false],
    ]);
});

/** @return array<string, mixed> */
function ruleForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Weekly coffee tip',
        'customer_id' => test()->brand->getKey(),
        'title' => 'Tip of the week',
        'body' => 'This week: grind size matters more than you think.',
        'frequency' => 'weekly',
        'interval' => 1,
        // ISO-8601: 2 is Tuesday. Carbon::TUESDAY happens to agree; Sunday
        // would not, which is why the form posts literal ISO numbers.
        'weekdays' => [2],
        'time_of_day' => '09:00',
        'starts_on' => '2026-03-02',
    ], $overrides);
}

it('creates a rule and the posts it implies', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.posts.recurring.store'), ruleForm())
        ->assertRedirect(route('agency.posts.recurring.index'));

    $rule = RecurringPostRule::query()->sole();

    expect($rule->name)->toBe('Weekly coffee tip')
        ->and($rule->weekdays)->toBe([2])
        ->and($rule->timezone)->toBe('UTC');

    /*
     | Materialised on save rather than at 02:10 tomorrow. Somebody who has
     | just described a cadence wants to see what it produces; a screen that
     | says "come back tomorrow" is how a feature gets reported as broken.
     */
    expect(Post::query()->where('source', 'recurring')->count())->toBeGreaterThan(0);

    $post = Post::query()->where('source', 'recurring')->orderBy('occurrence_date')->first();

    expect($post->body)->toBe('This week: grind size matters more than you think.')
        ->and($post->recurring_post_rule_id)->toBe($rule->getKey());
});

it('lists a rule with the dates it will actually fire on', function (): void {
    RecurringPostRule::factory()->forCustomer($this->brand)->weekly([2])->create([
        'name' => 'Weekly coffee tip',
        'created_by_user_id' => $this->owner->getKey(),
        'starts_on' => '2026-03-02',
    ]);

    asAgencyUser($this->owner)
        ->get(route('agency.posts.recurring.index'))
        ->assertOk()
        ->assertSee('Weekly coffee tip')
        // The cadence in words, and the next real dates beside it.
        ->assertSee('Every week on Tue at 09:00')
        ->assertSee('Tue 3 Mar');
});

it('refuses a weekly rule with no day chosen', function (): void {
    /*
     | The worst outcome this feature has is a rule that saves cleanly, looks
     | active on the list and generates nothing at all. A weekly rule with no
     | weekdays is exactly that, so it is refused at the door.
     */
    asAgencyUser($this->owner)
        ->post(route('agency.posts.recurring.store'), ruleForm(['weekdays' => []]))
        ->assertSessionHasErrors('weekdays');

    expect(RecurringPostRule::query()->count())->toBe(0);
});

it('refuses a monthly rule with no day of month', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.posts.recurring.store'), ruleForm([
            'frequency' => 'monthly',
            'weekdays' => [],
            'day_of_month' => null,
        ]))
        ->assertSessionHasErrors('day_of_month');
});

it('refuses a rule that starts in the past', function (): void {
    asAgencyUser($this->owner)
        ->post(route('agency.posts.recurring.store'), ruleForm(['starts_on' => '2026-01-01']))
        ->assertSessionHasErrors('starts_on');
});

it('stores only the cadence fields the chosen frequency uses', function (): void {
    /*
     | A stale day_of_month left on a rule that was built as weekly is how a
     | later edit resurrects a cadence nobody chose.
     */
    asAgencyUser($this->owner)
        ->post(route('agency.posts.recurring.store'), ruleForm(['day_of_month' => 15]));

    expect(RecurringPostRule::query()->sole()->day_of_month)->toBeNull();
});

it('will not aim a rule at another brand social account', function (): void {
    $other = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Rival Roasters',
    ]);

    $theirs = SocialAccount::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'customer_id' => $other->getKey(),
        'status' => AccountStatus::Active->value,
    ]);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.recurring.store'), ruleForm(['accounts' => [$theirs->getKey()]]));

    // Ids arrive from a form, so brand ownership is checked rather than
    // trusted. Posting one brand's content to another's feed weekly, for ever,
    // is the shape of mistake a standing rule makes expensive.
    expect(RecurringPostRule::query()->sole()->accounts)->toHaveCount(0);
});

it('pauses a rule without touching the posts it already made', function (): void {
    asAgencyUser($this->owner)->post(route('agency.posts.recurring.store'), ruleForm());

    $rule = RecurringPostRule::query()->sole();
    $before = Post::query()->count();

    asAgencyUser($this->owner)
        ->post(route('agency.posts.recurring.toggle', $rule))
        ->assertRedirect();

    expect($rule->fresh()->is_active)->toBeFalse()
        // Pausing stops future occurrences. Cancelling next week's approved
        // post is a much bigger action than the button implies.
        ->and(Post::query()->count())->toBe($before);
});

it('keeps the posts when the rule is deleted', function (): void {
    asAgencyUser($this->owner)->post(route('agency.posts.recurring.store'), ruleForm());

    $rule = RecurringPostRule::query()->sole();
    $before = Post::query()->count();

    expect($before)->toBeGreaterThan(0);

    asAgencyUser($this->owner)
        ->delete(route('agency.posts.recurring.destroy', $rule))
        ->assertRedirect(route('agency.posts.recurring.index'));

    expect(RecurringPostRule::query()->count())->toBe(0)
        ->and(Post::query()->count())->toBe($before);
});

it('does not offer the screen to somebody who cannot create posts', function (): void {
    $analyst = User::factory()->create();
    $analyst->tenants()->attach($this->tenant->getKey());
    setPermissionsTeamId($this->tenant->getKey());
    $analyst->assignRole('Analyst');

    // Analyst may read content but not author it.
    asAgencyUser($analyst)
        ->get(route('agency.posts.recurring.create'))
        ->assertForbidden();

    asAgencyUser($analyst)
        ->post(route('agency.posts.recurring.store'), ruleForm())
        ->assertForbidden();
});

it('returns 404 for a rule in another tenant', function (): void {
    /*
     | 404, not 403. bootstrap/app.php orders ResolveTenant before
     | SubstituteBindings precisely so the global scope filters a foreign row
     | out during binding -- a 403 here would confirm the rule exists.
     |
     | Written from the owner's own legitimate session, reaching for somebody
     | else's record. asAgencyUser() always seats the caller in test()->tenant,
     | so acting "as" a user from the other agency would test a forged session
     | rather than cross-tenant record access.
     */
    $otherTenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Rival Agency');

    withoutTenantContext();

    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    $foreignRule = RecurringPostRule::factory()->forCustomer($foreignBrand)->create();

    actingForTenant($this->tenant);

    asAgencyUser($this->owner)
        ->post(route('agency.posts.recurring.toggle', $foreignRule))
        ->assertNotFound();

    asAgencyUser($this->owner)
        ->delete(route('agency.posts.recurring.destroy', $foreignRule))
        ->assertNotFound();
});

it('lists only this agency own rules', function (): void {
    asAgencyUser($this->owner)->post(route('agency.posts.recurring.store'), ruleForm());

    $otherTenant = app(ProvisionTenantService::class)
        ->execute(User::factory()->create(), 'Rival Agency');

    withoutTenantContext();
    $foreignBrand = Customer::factory()->create(['tenant_id' => $otherTenant->getKey()]);
    RecurringPostRule::factory()->forCustomer($foreignBrand)->create(['name' => 'Rival weekly promo']);

    actingForTenant($this->tenant);

    asAgencyUser($this->owner)
        ->get(route('agency.posts.recurring.index'))
        ->assertOk()
        ->assertSee('Weekly coffee tip')
        ->assertDontSee('Rival weekly promo');
});

it('leaves occurrences as drafts when the brand requires client approval', function (): void {
    $this->brand->update(['settings' => ['approval_required' => true]]);

    asAgencyUser($this->owner)->post(route('agency.posts.recurring.store'), ruleForm());

    // The gate holds through the screen, not only in the service.
    expect(Post::query()->where('source', 'recurring')->count())->toBeGreaterThan(0)
        ->and(Post::query()->where('status', '!=', PostStatus::Draft->value)->exists())
        ->toBeFalse();
});
