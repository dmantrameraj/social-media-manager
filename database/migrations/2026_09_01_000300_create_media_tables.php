<?php

declare(strict_types=1);

use App\Domain\Media\Enums\MediaStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_folders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()
                ->constrained('media_folders')->cascadeOnDelete();

            $table->string('name', 120);

            // Set on seeded folders (logos, products, reels, ...) so they can
            // be referenced by key and protected from deletion.
            $table->string('system_key', 40)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['customer_id', 'parent_id', 'name'], 'media_folders_unique_name');
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()
                ->constrained('media_folders')->nullOnDelete();

            // Stored per row so a migration from local to S3 can proceed
            // file-by-file with no flag day -- old and new coexist.
            $table->string('disk', 40);
            $table->string('path', 500);

            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // sha256 of the file, for dedupe and integrity checks.
            $table->char('checksum', 64)->nullable();

            $table->string('thumbnail_path', 500)->nullable();
            $table->json('variants')->nullable();

            $table->string('status', 20)->default(MediaStatus::Uploading->value);

            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'checksum']);
            // Storage-quota accounting scans by tenant and status.
            $table->index(['tenant_id', 'status']);
        });

        // Deferred from the customers migration: media did not exist yet.
        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('logo_media_id')
                ->references('id')->on('media')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['logo_media_id']);
        });

        Schema::dropIfExists('media');
        Schema::dropIfExists('media_folders');
    }
};
