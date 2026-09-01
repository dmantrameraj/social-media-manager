<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Services\ProvisionTenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Self-serve signup.
 *
 * A user and their first agency are created together: an agency user with no
 * tenant has nowhere to go, and a tenant with no owner is unadministrable. Both
 * happen in one transaction so a half-registered account is impossible.
 *
 * Super Admin manual activation reaches ProvisionTenantService by a different
 * route but runs the same provisioning, so the two paths cannot drift.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:160'],
            'email' => [
                'required', 'string', 'email', 'max:190',
                // Ignores soft-deleted users, so a cancelled account does not
                // permanently block its own address from re-registering.
                'unique:users,email,NULL,id,deleted_at,NULL',
            ],
            'password' => $this->passwordRules(),
            'agency_name' => ['required', 'string', 'max:160'],
            'timezone' => ['nullable', 'string', 'timezone'],
        ], [
            'agency_name.required' => 'Please tell us your agency or business name.',
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'timezone' => $input['timezone'] ?? config('app.timezone'),
            ]);

            app(ProvisionTenantService::class)->execute(
                owner: $user,
                name: $input['agency_name'],
                timezone: $input['timezone'] ?? null,
            );

            return $user;
        });
    }
}
