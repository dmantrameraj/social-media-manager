<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Platform\Models\BrandingSetting;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrandingSetting> */
class BrandingSettingFactory extends Factory
{
    protected $model = BrandingSetting::class;

    public function definition(): array
    {
        return [
            'tenant_id' => fn (): int => Tenant::factory()->create()->getKey(),
            'app_name' => 'Bright Digital Social',
            'support_email' => 'help@brightdigital.test',
            'primary_color' => '#0ea5e9',
            'secondary_color' => '#0f172a',
        ];
    }

    /** Only a name set, which is the common partial case. */
    public function nameOnly(): self
    {
        return $this->state(fn (): array => [
            'support_email' => null,
            'primary_color' => null,
            'secondary_color' => null,
        ]);
    }
}
