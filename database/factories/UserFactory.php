<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'timezone' => 'Asia/Kolkata',
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => [
            'email_verified_at' => null,
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'status' => UserStatus::Disabled,
        ]);
    }

    /**
     * is_super_admin is guarded on the model, so it is set here via the
     * factory's raw attribute path rather than through fill().
     */
    public function superAdmin(): static
    {
        return $this->state(fn (): array => [
            'is_super_admin' => true,
        ]);
    }

    /** Attach the user to a tenant with an active membership. */
    public function memberOf(Tenant $tenant, MembershipStatus $status = MembershipStatus::Active): static
    {
        return $this->afterCreating(function (User $user) use ($tenant, $status): void {
            $user->tenants()->attach($tenant->getKey(), [
                'status' => $status->value,
                'joined_at' => now(),
            ]);
        });
    }
}
