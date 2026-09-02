<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-brand autopilot configuration.
 *
 * A table rather than a settings JSON blob because the scheduler queries it
 * every run to find which brands are due -- that is a relational question, not
 * a flexible-settings one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autopilot_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Explicit per-brand opt-in. Autopilot never runs for a brand
            // nobody switched it on for.
            $table->boolean('enabled')->default(false);

            $table->unsignedSmallInteger('posts_per_week')->default(3);
            $table->json('platforms')->nullable();
            $table->json('themes')->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('customer_id');
            // Drives the due-brand sweep.
            $table->index(['enabled', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autopilot_settings');
    }
};
