<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\PortalRole;
use App\Domain\Identity\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client logins, on the `customer` guard.
 *
 * A separate table (rather than a type column on users) is what makes it
 * impossible for a portal session to resolve to a User model: the guard
 * resolves through a different provider entirely. See docs/04-AUTH-RBAC.md §1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_portal_users', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('email', 190);
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();

            $table->string('status', 20)->default(UserStatus::Active->value);

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 10)->default('en');

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->foreignId('invited_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Tenant-scoped, deliberately. The same person working with two
            // agencies has two logins -- a shared portal identity would create
            // a cross-tenant join we have no reason to take on.
            $table->unique(['tenant_id', 'email']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('customer_portal_user_customer', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_portal_user_id')
                ->constrained('customer_portal_users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->string('role', 20)->default(PortalRole::Approver->value);

            $table->timestamps();

            $table->unique(
                ['customer_portal_user_id', 'customer_id'],
                'portal_user_customer_unique',
            );
            $table->index(['customer_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_portal_user_customer');
        Schema::dropIfExists('customer_portal_users');
    }
};
