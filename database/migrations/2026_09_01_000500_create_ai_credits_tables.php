<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI credit accounting.
 *
 * ai_credit_transactions is the source of truth and is append-only.
 * ai_credit_accounts.balance is a cache for fast reads, reconciled by
 * `ai:reconcile-credits`. A bare editable integer is explicitly not acceptable
 * -- see docs/08-AI-ARCHITECTURE.md §5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Cache of SUM(ai_credit_transactions.amount). Never written
            // directly outside the ledger service.
            $table->bigInteger('balance')->default(0);

            // Held against in-flight generations. Available = balance - reserved.
            $table->bigInteger('reserved')->default(0);

            $table->bigInteger('monthly_allowance')->default(0);

            // Period boundaries follow the tenant's billing anniversary, not
            // the calendar month.
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();

            $table->boolean('rollover_enabled')->default(false);
            $table->bigInteger('rollover_cap')->nullable();

            $table->timestamps();

            $table->unique('tenant_id');
            // Drives ai:reset-monthly-credits.
            $table->index('period_end');
        });

        Schema::create('ai_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_credit_account_id')
                ->constrained('ai_credit_accounts')->cascadeOnDelete();

            $table->string('type', 20);

            // Signed, so the ledger sums directly to the balance.
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');

            // What this transaction relates to (a generation, a subscription,
            // an admin action). Polymorphic but not an Eloquent morph, because
            // targets live in several modules and we never eager-load them.
            $table->string('reference_type', 120)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            // A duplicated request cannot double-charge.
            $table->string('idempotency_key', 120)->nullable()->unique();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();

            $table->string('actor_type', 40)->nullable();
            $table->string('note', 500)->nullable();
            $table->json('meta')->nullable();

            // Append-only: created_at only, no updated_at, no soft delete.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['ai_credit_account_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_transactions');
        Schema::dropIfExists('ai_credit_accounts');
    }
};
