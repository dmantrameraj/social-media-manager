<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which purge warnings have already gone out, keyed by stage.
 *
 * JSON rather than a column per stage so the warning schedule stays a config
 * value: adding a 1-day warning later should not need a migration, and a
 * deployment that changes the stages must not silently re-warn everybody who
 * already heard about the old ones.
 *
 * Shaped {"30": "2026-09-03T04:05:00+00:00", "7": ...}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->json('purge_warnings_sent')->nullable()->after('purged_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('purge_warnings_sent');
        });
    }
};
