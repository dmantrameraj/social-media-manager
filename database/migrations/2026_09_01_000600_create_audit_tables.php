<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: platform-level actions (plan edits, feature flags)
            // belong to no tenant.
            $table->foreignId('tenant_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('actor_type', 40);
            $table->unsignedBigInteger('actor_id')->nullable();

            // Set when the action happened inside an impersonation session, so
            // the trail attributes it to both identities.
            $table->unsignedBigInteger('impersonator_user_id')->nullable();

            $table->string('action', 120);

            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            // Both pass through the redaction filter before write. A secret
            // must never be recoverable from the audit trail.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->uuid('request_id')->nullable();

            // Append-only: created_at only. The model has no update path.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('action');
        });

        Schema::create('login_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->nullable()
                ->constrained()->nullOnDelete();

            // Polymorphic across both guards. Not a constrained FK: the row
            // must survive the account being deleted.
            //
            // NULLABLE, because a failed login against an address that matches
            // no account has no principal to point at -- and that is precisely
            // the event most worth recording.
            $table->string('authenticatable_type', 120)->nullable();
            $table->unsignedBigInteger('authenticatable_id')->nullable();

            // The address that was tried. Recorded so credential-stuffing
            // against non-existent accounts is visible. Never accompanied by
            // the attempted password.
            $table->string('attempted_email', 190)->nullable();

            $table->string('event', 40);

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('device', 80)->nullable();
            $table->string('platform', 80)->nullable();
            $table->string('browser', 80)->nullable();
            $table->string('session_id', 191)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(
                ['authenticatable_type', 'authenticatable_id', 'created_at'],
                'login_histories_actor_index',
            );
            $table->index(['event', 'created_at']);
        });

        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('super_admin_user_id')
                ->constrained('users')->cascadeOnDelete();

            $table->string('target_type', 120);
            $table->unsignedBigInteger('target_id');

            $table->foreignId('tenant_id')->nullable()
                ->constrained()->nullOnDelete();

            // Required. An impersonation with no stated reason is
            // indistinguishable from an abuse of access.
            $table->string('reason', 500);

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();

            $table->index(['super_admin_user_id', 'started_at']);
            // Finds still-open sessions for the timeout sweeper.
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('login_histories');
        Schema::dropIfExists('audit_logs');
    }
};
