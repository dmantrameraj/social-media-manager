<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Enums\TenantType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            // Reseller hierarchy. Nullable and unused in V1 -- present so a
            // reseller tier can be inserted above agencies later without a
            // data migration. See docs/03-TENANCY.md §1.
            $table->foreignId('parent_tenant_id')->nullable()
                ->constrained('tenants')->nullOnDelete();

            $table->string('type', 20)->default(TenantType::Agency->value);
            $table->string('name', 160);
            $table->string('slug', 80)->unique();

            $table->string('status', 20)->default(TenantStatus::Trialing->value);

            // Set after the owner user exists; the FK is added in a follow-up
            // migration because users and tenants reference each other.
            $table->unsignedBigInteger('owner_user_id')->nullable();

            $table->string('timezone', 64)->default('UTC');
            $table->string('locale', 10)->default('en');
            $table->char('currency', 3)->default('INR');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Retention clock. The purge job only considers rows whose
            // purge_after has passed -- see docs/10-SECURITY.md §9.
            $table->timestamp('purge_after')->nullable();

            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('purge_after');
            $table->index('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
