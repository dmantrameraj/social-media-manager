<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Customers\Models\Customer;
use App\Domain\Publishing\Enums\RecurrenceFrequency;
use App\Domain\Publishing\Models\RecurringPostRule;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<RecurringPostRule> */
class RecurringPostRuleFactory extends Factory
{
    protected $model = RecurringPostRule::class;

    public function definition(): array
    {
        return [
            // Lazily resolved so a caller-supplied tenant_id is honoured and
            // the brand lands in the SAME tenant.
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),
            'customer_id' => fn (array $attributes): int => Customer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),
            'name' => fake()->sentence(3),
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'frequency' => RecurrenceFrequency::Weekly->value,
            'interval' => 1,
            'weekdays' => [Carbon::TUESDAY],
            'day_of_month' => null,
            'time_of_day' => '09:00:00',
            'timezone' => 'Asia/Kolkata',
            // Yesterday, so a rule is materialisable the moment it is made.
            // Starting "today" leaves tests at the mercy of the clock.
            'starts_on' => Carbon::now()->subDay()->toDateString(),
            'ends_on' => null,
            'is_active' => true,
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->getKey(),
            'timezone' => $customer->effectiveTimezone(),
        ]);
    }

    public function daily(int $interval = 1): static
    {
        return $this->state(fn (): array => [
            'frequency' => RecurrenceFrequency::Daily->value,
            'interval' => $interval,
            'weekdays' => null,
            'day_of_month' => null,
        ]);
    }

    /** @param  list<int>  $weekdays  ISO-8601 day numbers, 1 = Monday */
    public function weekly(array $weekdays, int $interval = 1): static
    {
        return $this->state(fn (): array => [
            'frequency' => RecurrenceFrequency::Weekly->value,
            'interval' => $interval,
            'weekdays' => $weekdays,
            'day_of_month' => null,
        ]);
    }

    public function monthly(int $dayOfMonth, int $interval = 1): static
    {
        return $this->state(fn (): array => [
            'frequency' => RecurrenceFrequency::Monthly->value,
            'interval' => $interval,
            'weekdays' => null,
            'day_of_month' => $dayOfMonth,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
