<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans describe the product; plan_prices describe what it costs.
 *
 * The split lets one plan carry monthly and yearly prices in several
 * currencies with different gateway plan ids, without duplicating features.
 * See docs/09-BILLING.md §2.
 *
 * These are platform-level tables, not tenant-owned: no tenant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->text('description')->nullable();

            // is_public=false covers bespoke enterprise plans and the trial
            // plan, which must exist but never appear on the pricing page.
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);

            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'is_public']);
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            $table->string('billing_period', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');

            $table->string('gateway', 20);
            $table->string('gateway_plan_id', 120)->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['plan_id', 'billing_period', 'currency', 'gateway'],
                'plan_prices_unique',
            );
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            // Validated against config('entitlements.keys') on save, so a typo
            // cannot create a silently unenforced limit.
            $table->string('key', 80);
            $table->string('value_type', 20)->default(EntitlementType::Limit->value);

            // Null for boolean/unlimited types.
            $table->bigInteger('value')->nullable();

            $table->timestamps();

            $table->unique(['plan_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
