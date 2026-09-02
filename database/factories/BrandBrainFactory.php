<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\AI\Models\BrandBrain;
use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrandBrain> */
class BrandBrainFactory extends Factory
{
    protected $model = BrandBrain::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),
            'customer_id' => fn (array $attributes): int => Customer::factory()
                ->create(['tenant_id' => $attributes['tenant_id']])
                ->getKey(),

            'business_description' => 'A neighbourhood coffee roastery.',
            'industry' => 'Food and beverage',
            'brand_tone' => 'Warm, unpretentious, a little playful',
            'primary_language' => 'en',
            'target_audience' => ['Local office workers', 'Weekend families'],
            'usps' => ['Roasted on site daily', 'Single-origin beans'],
            'preferred_keywords' => ['specialty coffee', 'single origin'],
            'ctas' => ['Visit us this weekend'],
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $customer->tenant_id,
            'customer_id' => $customer->getKey(),
        ]);
    }

    /** @param  list<string>  $words */
    public function forbidding(array $words): static
    {
        return $this->state(fn (): array => ['forbidden_words' => $words]);
    }
}
