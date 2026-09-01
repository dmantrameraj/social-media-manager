<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements\Enums\EntitlementType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\PaymentStatus;
use App\Domain\Billing\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_price_id')->nullable()
                ->constrained('plan_prices')->nullOnDelete();

            $table->string('status', 20)->default(SubscriptionStatus::Trialing->value);
            $table->string('gateway', 20)->default(PaymentGateway::Manual->value);

            $table->string('gateway_subscription_id', 120)->nullable();
            $table->string('gateway_customer_id', 120)->nullable();

            $table->unsignedInteger('quantity')->default(1);

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);

            // Set when a Super Admin activates the tenant manually.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            // Webhook handlers look subscriptions up by gateway id.
            $table->unique(
                ['gateway', 'gateway_subscription_id'],
                'subscriptions_gateway_unique',
            );
            // Drives billing:process-lifecycle.
            $table->index(['status', 'current_period_end']);
            $table->index(['status', 'grace_ends_at']);
        });

        Schema::create('subscription_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('key', 80);
            $table->string('value_type', 20)->default(EntitlementType::Limit->value);
            $table->bigInteger('value')->nullable();

            // Required by the service; a limit change with no stated reason is
            // unauditable six months later.
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
            $table->index('expires_at');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()
                ->constrained()->nullOnDelete();

            // Allocated at issue time under a row lock, not AUTO_INCREMENT,
            // because accounting does not tolerate gaps and AUTO_INCREMENT
            // gaps on rollback. Null while draft.
            $table->string('number', 40)->nullable()->unique();

            $table->string('status', 20)->default(InvoiceStatus::Draft->value);
            $table->char('currency', 3);

            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->unsignedBigInteger('tax_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);

            $table->foreignId('coupon_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->string('pdf_path', 500)->nullable();
            // Snapshot of the billing address at issue time -- it must not
            // change retroactively when the tenant edits their details.
            $table->json('billing_details')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'issued_at']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount_minor');
            $table->unsignedBigInteger('amount_minor');

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('gateway', 20);
            $table->string('gateway_payment_id', 120);
            $table->string('gateway_order_id', 120)->nullable();

            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 20)->default(PaymentStatus::Created->value);
            $table->string('method', 40)->nullable();

            $table->string('failure_code', 80)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Redacted before write. Never contains card data -- we store no
            // card data at any point; the gateway holds it.
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['gateway', 'gateway_payment_id'], 'payments_gateway_unique');
            $table->index(['tenant_id', 'status']);
        });

        // Deferred from the coupons migration: subscriptions and invoices did
        // not exist yet.
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table) {
            $table->dropForeign(['subscription_id']);
            $table->dropForeign(['invoice_id']);
        });

        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscription_overrides');
        Schema::dropIfExists('subscriptions');
    }
};
