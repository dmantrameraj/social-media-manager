<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;

/*
 | Found in a browser: a wrong two-factor code re-rendered the challenge with
 | no message at all. Nothing told the user their code was rejected, which is
 | indistinguishable from the button not working.
 |
 | These assert the feedback itself, not just the status code -- a redirect
 | back with no visible reason is what the bug looked like.
 */

it('tells a user their password was wrong', function (): void {
    $user = User::factory()->create();
    $user->forceFill(['password' => bcrypt('correct-horse-battery')])->save();

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors();

    $this->followingRedirects()
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])
        ->assertOk()
        ->assertSee('These credentials do not match our records.');
});

it('tells a user their two-factor code was wrong', function (): void {
    $user = User::factory()->create();
    $user->forceFill([
        'password' => bcrypt('correct-horse-battery'),
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('two-factor.login'));

    $this->from(route('two-factor.login'))
        ->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertSessionHasErrors();

    $this->followingRedirects()
        ->post(route('two-factor.login.store'), ['code' => '000000'])
        ->assertOk()
        ->assertSee('The provided two factor authentication code was invalid.');
});

it('accepts a recovery code at the challenge', function (): void {
    // The browser walkthrough could not get past this, and a recovery code that
    // does not work is an account-recovery failure, not a cosmetic one.
    $user = User::factory()->create();
    $user->forceFill([
        'password' => bcrypt('correct-horse-battery'),
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'aaaa-bbbb'])
        ->assertRedirect();

    expect(auth('web')->id())->toBe($user->getKey());
});
