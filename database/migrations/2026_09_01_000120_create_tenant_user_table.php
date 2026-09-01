<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\MembershipStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant membership. This table is the authority for "may this user act inside
 * this tenant" and is re-read on every request by ResolveTenant -- never
 * trusted from the session alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('status', 20)->default(MembershipStatus::Active->value);

            $table->foreignId('invited_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('joined_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
            // Leads with user_id: the hot query is "which tenants may this
            // user access", run on every request.
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};
