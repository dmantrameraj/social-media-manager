<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When this tenant's data was actually purged.
 *
 * purge_after says when a purge becomes DUE and is cleared once it runs, so on
 * its own it cannot distinguish a tenant whose data was destroyed from a
 * cancelled one that was never due. That difference is the whole answer to
 * "was this customer's data deleted?", which is a question with a legal
 * deadline attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestamp('purged_at')->nullable()->after('purge_after');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('purged_at');
        });
    }
};
