<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-cutting platform tables.
 *
 * `domains` and `branding_settings` are SCHEMA STUBS in V1: the tables and a
 * BrandingResolver ship so no Blade template hardcodes platform branding, but
 * the white-label editing UI and host-based tenant resolution do not.
 * Schema stub is not the same as feature -- see docs/00-PROJECT-OVERVIEW.md §3.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Provider-agnostic webhook inbox. The endpoint verifies, deduplicates
        // and records, then returns 200 -- all processing is queued, so a slow
        // handler can never cause a gateway retry-storm.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            $table->string('provider', 40);
            $table->string('event_id', 190);
            $table->string('event_type', 120);

            $table->boolean('signature_verified')->default(false);
            $table->json('payload')->nullable();

            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            // The deduplication guarantee.
            $table->unique(['provider', 'event_id'], 'webhook_events_unique');
            $table->index(['status', 'received_at']);
        });

        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();

            $table->string('key', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();

            $table->boolean('is_enabled_globally')->default(false);
            $table->json('rollout')->nullable();

            $table->timestamps();
        });

        Schema::create('feature_flag_tenant', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feature_flag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->boolean('is_enabled')->default(false);

            $table->timestamps();

            $table->unique(['feature_flag_id', 'tenant_id'], 'feature_flag_tenant_unique');
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Global unique by necessity: a hostname maps to exactly one
            // tenant. This is the one place a non-tenant-scoped unique key is
            // correct, because host-to-tenant resolution demands it.
            $table->string('hostname', 190)->unique();

            $table->string('type', 20)->default('subdomain');
            $table->boolean('is_primary')->default(false);

            $table->string('verification_token', 80)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('ssl_status', 20)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'is_primary']);
        });

        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('app_name', 120)->nullable();

            $table->foreignId('logo_media_id')->nullable()
                ->constrained('media')->nullOnDelete();
            $table->foreignId('favicon_media_id')->nullable()
                ->constrained('media')->nullOnDelete();
            $table->foreignId('login_background_media_id')->nullable()
                ->constrained('media')->nullOnDelete();

            $table->string('primary_color', 9)->nullable();
            $table->string('secondary_color', 9)->nullable();

            $table->string('email_from_name', 120)->nullable();
            $table->string('email_from_address', 190)->nullable();
            $table->string('support_email', 190)->nullable();

            $table->text('custom_css')->nullable();

            $table->timestamps();

            $table->unique('tenant_id');
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('event_key', 80);
            $table->string('channel', 20);
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->unique(
                ['user_id', 'event_key', 'channel'],
                'notification_preferences_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('branding_settings');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('feature_flag_tenant');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('webhook_events');
    }
};
