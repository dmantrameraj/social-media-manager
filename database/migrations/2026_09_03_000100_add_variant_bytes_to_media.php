<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bytes consumed by generated variants, tracked separately from the original.
 *
 * Storage quota is summed from media.size_bytes, which is the size of the file
 * the user uploaded. Thumbnails and previews are real files on the same disk,
 * so without this a tenant sitting on their limit still writes two extra
 * derivatives per image and the quota never notices.
 *
 * Kept out of size_bytes rather than folded into it: that column is the
 * uploaded file's size, shown to users and compared against the per-upload
 * limit. Overloading it would make both of those wrong to fix an accounting
 * problem that has its own column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->unsignedBigInteger('variant_bytes')->default(0)->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('variant_bytes');
        });
    }
};
