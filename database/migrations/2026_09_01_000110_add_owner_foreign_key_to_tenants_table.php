<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tenants.owner_user_id -> users.id
 *
 * Split into its own migration because users and tenants reference each other:
 * users has no tenant_id (membership lives in tenant_user), but tenants points
 * at an owning user. Both tables must exist before this key can be added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->foreign('owner_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['owner_user_id']);
            $table->dropIndex(['owner_user_id']);
        });
    }
};
