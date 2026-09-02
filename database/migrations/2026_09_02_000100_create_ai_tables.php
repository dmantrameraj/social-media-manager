<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-brand grounding context. One row per customer.
        Schema::create('brand_brains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->text('business_description')->nullable();
            $table->string('industry', 120)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('brand_tone', 190)->nullable();
            $table->text('brand_voice_notes')->nullable();
            $table->string('primary_language', 10)->default('en');

            /*
             | JSON is appropriate for these: they are list-shaped, free-form,
             | read only as a whole to build a prompt, and never joined or
             | aggregated. If forbidden-word enforcement later needs
             | cross-brand reporting, promote that one field to a table.
             */
            $table->json('target_audience')->nullable();
            $table->json('locations')->nullable();
            $table->json('products')->nullable();
            $table->json('services')->nullable();
            $table->json('usps')->nullable();
            $table->json('competitors')->nullable();
            $table->json('ctas')->nullable();
            $table->json('forbidden_words')->nullable();
            $table->json('preferred_keywords')->nullable();
            $table->json('brand_colors')->nullable();
            $table->json('goals')->nullable();
            $table->json('content_themes')->nullable();
            $table->json('languages')->nullable();
            $table->json('extra')->nullable();

            $table->timestamps();

            $table->unique('customer_id');
            $table->index('tenant_id');
        });

        // One row per generation attempt, successful or not.
        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('feature', 60);
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->string('status', 20)->default('pending');

            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('credits_charged')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);

            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();

            // Purged on a schedule: these hold customer business content.
            $table->json('request_snapshot')->nullable();
            $table->json('response_snapshot')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'feature']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('brand_brains');
    }
};
