<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\SecretRedactor;
use App\Domain\Customers\Models\Customer;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;

beforeEach(function (): void {
    $this->logger = app(AuditLogger::class);
    $this->tenant = Tenant::factory()->create();
    actingForTenant($this->tenant);
});

it('records an action against an entity', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $user = User::factory()->create();

    $entry = $this->logger->log('customer.archived', $customer, actor: $user);

    expect($entry->action)->toBe('customer.archived')
        ->and($entry->auditable_type)->toBe(Customer::class)
        ->and($entry->auditable_id)->toBe($customer->getKey())
        ->and($entry->tenant_id)->toBe($this->tenant->getKey())
        ->and($entry->actor_type)->toBe(ActorType::User)
        ->and($entry->actor_id)->toBe($user->getKey());
});

it('attributes an actorless action to the system', function (): void {
    $entry = $this->logger->log('subscription.expired');

    expect($entry->actor_type)->toBe(ActorType::System)
        ->and($entry->actor_id)->toBeNull();
});

it('captures only the attributes that actually changed', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Before',
        'industry' => 'Retail',
    ]);

    // Captured before save, as the docblock requires: Laravel syncs originals
    // in finishSave(), so afterwards the "old" value is the new one.
    $original = $customer->getRawOriginal();

    $customer->name = 'After';
    $customer->save();

    $entry = $this->logger->logChanges('customer.updated', $customer, original: $original);

    expect($entry)->not->toBeNull()
        ->and($entry->new_values)->toHaveKey('name')
        ->and($entry->new_values['name'])->toBe('After')
        ->and($entry->old_values['name'])->toBe('Before')
        // industry did not change, so it must not appear.
        ->and($entry->new_values)->not->toHaveKey('industry');
});

it('records old values as unknown rather than lying when originals are gone', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Before',
    ]);

    $customer->name = 'After';
    $customer->save();

    // Called too late and with no explicit original -- the previous value is
    // genuinely unrecoverable here.
    $entry = $this->logger->logChanges('customer.updated', $customer);

    expect($entry->new_values['name'])->toBe('After')
        ->and($entry->old_values)->toBeNull();
});

it('captures originals correctly from an updated model event', function (): void {
    $customer = Customer::factory()->create([
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'Before',
    ]);

    $captured = null;

    Customer::updated(function (Customer $model) use (&$captured): void {
        // Inside the event, originals are still intact -- this is the
        // intended usage.
        $captured = app(AuditLogger::class)->logChanges('customer.updated', $model);
    });

    $customer->name = 'After';
    $customer->save();

    expect($captured)->not->toBeNull()
        ->and($captured->old_values['name'])->toBe('Before')
        ->and($captured->new_values['name'])->toBe('After');
});

it('writes nothing when nothing changed', function (): void {
    $customer = Customer::factory()->create(['tenant_id' => $this->tenant->getKey()]);
    $customer->save();

    expect($this->logger->logChanges('customer.updated', $customer))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Redaction -- the property that makes this log safe to keep
|--------------------------------------------------------------------------
*/

it('redacts secrets by attribute name', function (string $key): void {
    $entry = $this->logger->log('credential.updated', newValues: [
        $key => 'the-actual-secret-value',
        'label' => 'Meta App',
    ]);

    expect($entry->new_values[$key])->toBe(config('audit.placeholder'))
        ->and(json_encode($entry->new_values))->not->toContain('the-actual-secret-value')
        // Non-secret fields survive, or the log would be useless.
        ->and($entry->new_values['label'])->toBe('Meta App');
})->with([
    'password',
    'client_secret',
    'access_token',
    'refresh_token',
    'two_factor_secret',
    'api_key',
    'recovery_codes',
    'remember_token',
    'code_verifier',
]);

it('redacts case-insensitively and on partial matches', function (): void {
    $entry = $this->logger->log('x', newValues: [
        'META_CLIENT_SECRET' => 'leak-1',
        'refreshTokenValue' => 'leak-2',
        'Authorization' => 'leak-3',
    ]);

    expect(json_encode($entry->new_values))
        ->not->toContain('leak-1')
        ->not->toContain('leak-2')
        ->not->toContain('leak-3');
});

it('redacts secrets nested inside a settings blob', function (): void {
    $entry = $this->logger->log('x', newValues: [
        'settings' => [
            'provider' => 'meta',
            'credentials' => ['client_secret' => 'nested-leak'],
        ],
    ]);

    expect(json_encode($entry->new_values))->not->toContain('nested-leak')
        ->and($entry->new_values['settings']['provider'])->toBe('meta');
});

it('truncates very long values rather than storing a second copy', function (): void {
    $entry = $this->logger->log('x', newValues: [
        'body' => str_repeat('a', 5000),
    ]);

    expect(strlen($entry->new_values['body']))
        ->toBeLessThan(5000)
        ->and($entry->new_values['body'])->toEndWith('[truncated]');
});

it('never records a real user password when logging a created user', function (): void {
    $user = User::factory()->create();

    $entry = $this->logger->logCreated($user);

    expect($entry->new_values['password'])->toBe(config('audit.placeholder'))
        ->and($entry->new_values)->toHaveKey('email');
});

it('drops noisy timestamp attributes', function (): void {
    $entry = $this->logger->log('x', newValues: [
        'name' => 'Kept',
        'updated_at' => now()->toDateTimeString(),
        'created_at' => now()->toDateTimeString(),
    ]);

    expect($entry->new_values)->toHaveKey('name')
        ->and($entry->new_values)->not->toHaveKey('updated_at')
        ->and($entry->new_values)->not->toHaveKey('created_at');
});

/*
|--------------------------------------------------------------------------
| Immutability
|--------------------------------------------------------------------------
*/

it('refuses to update an audit row', function (): void {
    $entry = $this->logger->log('x');

    $entry->action = 'tampered';

    expect(fn () => $entry->save())->toThrow(RuntimeException::class);
});

it('refuses to delete an audit row', function (): void {
    $entry = $this->logger->log('x');

    expect(fn () => $entry->delete())->toThrow(RuntimeException::class);
});

it('keeps platform actions with no tenant', function (): void {
    withoutTenantContext();

    $entry = $this->logger->log('plan.updated');

    expect($entry->tenant_id)->toBeNull();
});

it('scopes a query to one tenant', function (): void {
    $other = Tenant::factory()->create();

    $this->logger->log('a');
    $this->logger->log('b', tenantId: $other->getKey());

    expect(AuditLog::query()->forTenant($this->tenant->getKey())->count())->toBe(1);
});

it('redacts through the standalone redactor too', function (): void {
    $redacted = app(SecretRedactor::class)->redact([
        'client_secret' => 'abc',
        'name' => 'visible',
    ]);

    expect($redacted['client_secret'])->toBe(config('audit.placeholder'))
        ->and($redacted['name'])->toBe('visible');
});
