<?php

declare(strict_types=1);

use App\Domain\Billing\Enums\CouponDuration;
use App\Domain\Billing\Enums\CouponType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            $table->string('code', 60)->unique();
            $table->string('name', 120)->nullable();

            $table->string('type', 20)->default(CouponType::Percent->value);
            // Percent: whole percent. Fixed: minor units of `currency`.
            $table->unsignedBigInteger('value');
            $table->char('currency', 3)->nullable();

            $table->string('duration', 20)->default(CouponDuration::Once->value);
            $table->unsignedSmallInteger('duration_months')->nullable();

            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->boolean('once_per_tenant')->default(true);

            // Restrict to specific plan ids; null means all plans.
            $table->json('applies_to_plan_ids')->nullable();

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();

            $table->timestamp('redeemed_at');
            $table->timestamps();

            // Prevents applying the same coupon to the same invoice twice --
            // a guarantee the storage layer CAN make unconditionally.
            //
            // once_per_tenant is deliberately NOT a unique index here: it is
            // configurable per coupon, and a repeating coupon legitimately
            // produces one redemption row per billing period. It is enforced
            // in RedeemCouponService inside a transaction that locks the
            // coupon row, which is where a conditional rule belongs.
            $table->unique(['coupon_id', 'invoice_id'], 'coupon_redemptions_invoice_unique');
            $table->index(['coupon_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
