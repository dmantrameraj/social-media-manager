<?php

declare(strict_types=1);

namespace App\Domain\AI\Models;

use App\Domain\Customers\Models\Customer;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\BrandBrainFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-brand grounding context: the thing that makes generated content specific
 * rather than generic, and the product's main AI differentiator.
 *
 * Everything here is USER-SUPPLIED and ends up inside a system prompt, so it
 * is treated as untrusted data throughout -- see BrandBrainContextBuilder.
 *
 * @property int $tenant_id
 * @property int $customer_id
 */
#[UseFactory(BrandBrainFactory::class)]
class BrandBrain extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'business_description', 'industry', 'website',
        'brand_tone', 'brand_voice_notes', 'primary_language',
        'target_audience', 'locations', 'products', 'services', 'usps',
        'competitors', 'ctas', 'forbidden_words', 'preferred_keywords',
        'brand_colors', 'goals', 'content_themes', 'languages', 'extra',
    ];

    protected $guarded = ['id', 'tenant_id', 'customer_id'];

    protected function casts(): array
    {
        return [
            'target_audience' => 'array',
            'locations' => 'array',
            'products' => 'array',
            'services' => 'array',
            'usps' => 'array',
            'competitors' => 'array',
            'ctas' => 'array',
            'forbidden_words' => 'array',
            'preferred_keywords' => 'array',
            'brand_colors' => 'array',
            'goals' => 'array',
            'content_themes' => 'array',
            'languages' => 'array',
            'extra' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * How complete this profile is, 0-100.
     *
     * Surfaced in the UI because output quality tracks it directly, and users
     * need to understand why thin input yields thin output rather than
     * concluding the AI is poor.
     */
    public function completeness(): int
    {
        $fields = (array) config('ai.brand_brain.completeness_fields', []);

        if ($fields === []) {
            return 0;
        }

        $filled = 0;

        foreach ($fields as $field) {
            $value = $this->getAttribute($field);

            $isFilled = is_array($value)
                ? $value !== []
                : is_string($value) && trim($value) !== '';

            if ($isFilled) {
                $filled++;
            }
        }

        return (int) round(($filled / count($fields)) * 100);
    }

    /** @return list<string> */
    public function forbiddenWords(): array
    {
        $words = [];

        foreach ((array) ($this->forbidden_words ?? []) as $word) {
            $word = trim((string) $word);

            if ($word !== '') {
                $words[] = $word;
            }
        }

        return $words;
    }
}
