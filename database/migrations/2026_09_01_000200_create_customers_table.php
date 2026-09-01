<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\CustomerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer is an AGENCY-SCOPED WORKSPACE, not a global business record.
 *
 * Two agencies serving the same restaurant hold two independent rows here, so
 * isolation between them is the ordinary tenant rule with nothing special added.
 * See docs/03-TENANCY.md §8.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Reserved for a future global business directory. Nothing points
            // at an identity today, so the table can be added later without
            // moving any foreign keys.
            $table->unsignedBigInteger('customer_identity_id')->nullable();

            $table->string('name', 160);
            $table->string('legal_name', 190)->nullable();
            $table->string('slug', 80);

            $table->string('industry', 120)->nullable();
            $table->string('website', 255)->nullable();

            // Drives scheduling. Defaults to the tenant timezone at creation.
            $table->string('timezone', 64)->default('UTC');

            $table->string('status', 20)->default(CustomerStatus::Active->value);

            // FK added after the media table exists.
            $table->unsignedBigInteger('logo_media_id')->nullable();

            $table->string('contact_name', 160)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 32)->nullable();

            // Approval requirements, default publish targets, posting slots.
            $table->json('settings')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Composite with tenant_id: a bare unique slug would leak the
            // existence of other tenants' brands through constraint errors.
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('customer_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['customer_id', 'user_id']);
            // The hot query is "which brands may this user see", checked on
            // every authorization decision.
            $table->index(['user_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_user');
        Schema::dropIfExists('customers');
    }
};
