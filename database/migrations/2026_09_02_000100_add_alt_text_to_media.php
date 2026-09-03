<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alt text for media.
 *
 * Two audiences, and both were being failed without it:
 *
 *   - a client reviewing content in the portal with a screen reader had no
 *     description of an image at all, only a filename;
 *   - the published post carried none either, so every platform this content
 *     goes out to received an image with no description.
 *
 * Nullable because it cannot be retrofitted onto existing rows, and because
 * requiring it at upload would push people to type "image" to get past the
 * form -- which is worse than absent, since a screen reader announces it as
 * though it were a description.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            /*
             | 1000 characters. The platforms differ -- and the exact ceilings
             | are [VERIFY] against current provider documentation before the
             | real adapters ship -- but 1000 is at or above the commonly cited
             | limit for the major networks, so the column is not the thing that
             | truncates. Adapters clamp to their own provider's limit.
             */
            $table->string('alt_text', 1000)->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('alt_text');
        });
    }
};
