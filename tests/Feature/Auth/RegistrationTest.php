<?php

declare(strict_types=1);

use App\Domain\AI\Models\AiCreditAccount;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => seedPermissions());

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Meraj Alam',
        'agency_name' => 'Bright Digital',
        'email' => 'meraj@example.com',
        'password' => 'correct-horse-battery-99',
        'password_confirmation' => 'correct-horse-battery-99',
    ], $overrides);
}

it('registers a user and provisions their agency in one step', function (): void {
    Event::fake([Registered::class]);

    $response = $this->post(route('register.store'), registrationPayload());

    $response->assertRedirect();

    $user = User::query()->where('email', 'meraj@example.com')->firstOrFail();
    $tenant = Tenant::query()->where('name', 'Bright Digital')->firstOrFail();

    expect($tenant->owner_user_id)->toBe($user->getKey())
        ->and($tenant->status)->toBe(TenantStatus::Trialing)
        ->and($user->belongsToTenant($tenant))->toBeTrue();
});

it('starts the trial clock at registration', function (): void {
    $this->post(route('register.store'), registrationPayload());

    $tenant = Tenant::query()->firstOrFail();

    expect($tenant->trial_ends_at)->not->toBeNull()
        ->and($tenant->trial_ends_at->isFuture())->toBeTrue();
});

it('opens an AI credit account for the new agency', function (): void {
    $this->post(route('register.store'), registrationPayload());

    expect(AiCreditAccount::query()->acrossTenants()->count())->toBe(1);
});

it('requires an agency name', function (): void {
    $response = $this->post(route('register.store'), registrationPayload(['agency_name' => '']));

    $response->assertSessionHasErrors('agency_name');
    expect(User::query()->count())->toBe(0);
});

it('rejects a password under twelve characters', function (): void {
    $response = $this->post(route('register.store'), registrationPayload([
        'password' => 'short1a',
        'password_confirmation' => 'short1a',
    ]));

    $response->assertSessionHasErrors('password');
    expect(User::query()->count())->toBe(0);
});

it('rejects a duplicate email', function (): void {
    User::factory()->create(['email' => 'meraj@example.com']);

    $response = $this->post(route('register.store'), registrationPayload());

    $response->assertSessionHasErrors('email');
});

it('creates no tenant when registration validation fails', function (): void {
    $this->post(route('register.store'), registrationPayload(['email' => 'not-an-email']));

    expect(Tenant::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

it('never allows is_super_admin through the registration payload', function (): void {
    $this->post(route('register.store'), registrationPayload(['is_super_admin' => true]));

    $user = User::query()->where('email', 'meraj@example.com')->firstOrFail();

    expect($user->is_super_admin)->toBeFalse();
});
