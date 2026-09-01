<?php

declare(strict_types=1);

use App\Domain\Social\Enums\AccountHealth;
use App\Domain\Social\Enums\AccountStatus;
use App\Domain\Social\Enums\ConnectionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-tenant developer app credentials. Every secret column is an
        // encrypted cast, write-only in the UI, and invisible to Super Admin.
        Schema::create('social_app_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('provider_key', 40);
            $table->string('label', 120);

            $table->text('client_id');
            $table->text('client_secret');
            $table->text('extra')->nullable();

            $table->string('redirect_override', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->string('last_verify_error', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'provider_key', 'label'], 'social_credentials_unique');
        });

        // One OAuth grant: one authorised identity on one provider.
        Schema::create('social_connections', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('provider_key', 40);
            $table->foreignId('social_app_credential_id')->nullable()
                ->constrained('social_app_credentials')->nullOnDelete();

            $table->string('external_user_id', 190);
            $table->string('name', 190)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('avatar_url', 500)->nullable();

            // GRANTED scopes as returned, never the requested set.
            $table->json('scopes')->nullable();

            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('token_type', 40)->default('Bearer');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();

            $table->string('status', 30)->default(ConnectionStatus::Active->value);
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error_code', 80)->nullable();

            $table->foreignId('connected_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'provider_key', 'external_user_id'], 'social_connections_unique');
            // Drives the token refresh sweeper.
            $table->index(['status', 'expires_at']);
        });

        // One publishable destination derived from a connection.
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_connection_id')
                ->constrained('social_connections')->cascadeOnDelete();

            $table->string('provider_key', 40);
            $table->string('account_type', 40);

            $table->string('external_id', 190);
            $table->string('name', 190);
            $table->string('username', 190)->nullable();
            $table->string('avatar_url', 500)->nullable();

            // Facebook Page tokens differ from the user token that found them.
            $table->text('page_access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->json('capabilities')->nullable();
            $table->json('scopes')->nullable();
            $table->string('timezone', 64)->nullable();

            $table->string('status', 30)->default(AccountStatus::Active->value);
            $table->string('health', 20)->default(AccountHealth::Healthy->value);

            $table->timestamp('last_published_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();

            // NOT soft-deleted: a soft-deleted row would collide with this key
            // on reconnect, which is the common path. Disconnect sets status
            // and nulls the tokens instead.
            $table->unique(['tenant_id', 'provider_key', 'external_id'], 'social_accounts_unique');
            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index('health');
        });

        // Short-lived OAuth CSRF/PKCE state. Single use.
        Schema::create('oauth_states', function (Blueprint $table) {
            $table->id();
            $table->char('state_hash', 64)->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('provider_key', 40);
            $table->foreignId('social_app_credential_id')->nullable()
                ->constrained('social_app_credentials')->nullOnDelete();

            $table->text('code_verifier')->nullable();
            $table->string('redirect_to', 500)->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_states');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('social_connections');
        Schema::dropIfExists('social_app_credentials');
    }
};
