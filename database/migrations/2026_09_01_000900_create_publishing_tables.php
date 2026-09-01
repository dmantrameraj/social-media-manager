<?php

declare(strict_types=1);

use App\Domain\Publishing\Enums\PostStatus;
use App\Domain\Publishing\Enums\TargetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('title', 190)->nullable();
            $table->string('status', 30)->default(PostStatus::Draft->value);
            $table->string('content_type', 30)->default('text');

            $table->text('body')->nullable();
            $table->string('link_url', 1000)->nullable();
            $table->text('first_comment')->nullable();

            // UTC. The timezone the author used is snapshotted alongside, so a
            // later brand timezone change does not silently retime posts.
            $table->timestamp('scheduled_at')->nullable();
            $table->string('timezone', 64)->nullable();

            $table->string('publish_mode', 20)->default('scheduled');
            $table->boolean('approval_required')->default(true);
            $table->string('source', 20)->default('manual');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });

        Schema::create('post_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version');
            $table->text('body')->nullable();
            $table->json('meta')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['post_id', 'version']);
        });

        // The engine's unit of work: one row per (post, social account).
        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')
                ->constrained('social_accounts')->cascadeOnDelete();

            $table->string('provider_key', 40);
            $table->string('status', 30)->default(TargetStatus::Pending->value);

            // Null means inherit the master body. Storing a copy would break
            // propagation of later master edits.
            $table->text('body_override')->nullable();
            $table->json('meta_override')->nullable();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('published_at')->nullable();

            // The idempotency anchor once we hold it.
            $table->string('external_post_id', 190)->nullable();
            $table->string('external_url', 1000)->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->timestamp('next_attempt_at')->nullable();

            $table->string('last_error_class', 40)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();

            // Stable across retries; unique so two rows can never share one.
            $table->char('idempotency_key', 64)->unique();

            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by', 100)->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'social_account_id'], 'post_targets_unique');
            // The due-work sweep.
            $table->index(['status', 'scheduled_at']);
            // Stale-lock recovery.
            $table->index(['status', 'locked_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();

            // Null applies the item to every target; set narrows it to one.
            $table->foreignId('post_target_id')->nullable()
                ->constrained('post_targets')->cascadeOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('role', 20)->default('primary');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'post_target_id', 'sort_order'], 'post_media_ordering');
        });

        // Immutable per-try log.
        Schema::create('publication_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_target_id')
                ->constrained('post_targets')->cascadeOnDelete();

            $table->unsignedSmallInteger('attempt_no');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->string('outcome', 30)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_class', 40)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_request_id', 190)->nullable();

            // Redacted before write; visible only to holders of posts.retry.
            $table->json('response_snapshot')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['post_target_id', 'attempt_no']);
        });

        Schema::create('post_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();

            $table->string('stage', 20);
            $table->string('action', 30);

            $table->string('actor_type', 40);
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->text('comment')->nullable();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['post_id', 'created_at']);
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('post_comments')->cascadeOnDelete();

            $table->string('author_type', 40);
            $table->unsignedBigInteger('author_id')->nullable();

            $table->text('body');
            // Never exposed to the portal -- enforced in the query AND policy.
            $table->boolean('is_internal')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['post_id', 'is_internal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_approvals');
        Schema::dropIfExists('publication_attempts');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('post_versions');
        Schema::dropIfExists('posts');
    }
};
