<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->string('name');
            // Global unique: a user is a cross-tenant principal and may belong
            // to several agencies. Tenant membership lives in tenant_user.
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            $table->string('phone', 32)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 10)->default('en');

            // Platform staff flag. Guarded on the model and settable only via
            // an audited console command -- never mass-assignable.
            $table->boolean('is_super_admin')->default(false)->index();

            // Fortify's two-factor columns are declared here rather than via
            // its published migration, so the whole users schema is in one
            // place. Both are encrypted casts on the model.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->string('status', 20)->default(UserStatus::Active->value);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Portal users are a separate principal in a separate table, so they
        // need their own reset broker table. Sharing one would let a reset
        // token issued for an agency user be consumed by a portal user with
        // the same address.
        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();

            // NOT a foreign key: this column holds an id from either `users`
            // or `customer_portal_users` depending on the guard, so `guard`
            // must be read alongside it. A custom session handler populates
            // both -- see docs/04-AUTH-RBAC.md §3.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('guard', 32)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            $table->index(['user_id', 'guard']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('customer_password_reset_tokens');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
