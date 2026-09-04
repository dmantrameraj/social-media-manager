<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\BrandingSettingFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One agency's white-label overrides.
 *
 * Every field is nullable and every null means "use the platform default", so
 * an agency can rename the product without also having to choose colours. A
 * partially-filled row is the normal case, not an incomplete one.
 *
 * @property int $tenant_id
 * @property string|null $app_name
 * @property string|null $support_email
 * @property string|null $primary_color
 * @property string|null $secondary_color
 */
#[UseFactory(BrandingSettingFactory::class)]
class BrandingSetting extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * Written through the settings controller after validation, never mass
     * assigned. A colour reaches a style attribute, so an unvalidated value is
     * a CSS injection rather than a cosmetic mistake.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * A hex colour, or null.
     *
     * Checked here as well as at the request boundary. The resolver hands
     * these straight to a template, and a value that arrived from a seeder, a
     * console command or a future import has passed no form validation at all.
     */
    public static function normaliseColor(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1
            ? strtolower($value)
            : null;
    }
}
