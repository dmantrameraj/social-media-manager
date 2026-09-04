<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         | One row per published post per account per collection.
         |
         | Metrics are stored TWICE on purpose. The normalised columns are ours
         | and are comparable across networks; `raw` keeps whatever the
         | provider actually returned.
         |
         | The reason for keeping both is that normalisation is lossy and
         | irreversible. A network that renames a field, or starts returning a
         | metric it never did, is discovered months later -- and without the
         | raw payload the only way to backfill is to re-poll an API that has
         | already aged the data out. Storage is cheap; a year of unrecoverable
         | history is not.
         |
         | Which provider field maps to which column is an ADAPTER decision and
         | is [VERIFY] against live documentation. Nothing here claims to know
         | what a given network calls "reach".
         */
        Schema::create('post_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            /*
             | The target, not the post: one post publishes to several accounts
             | and each carries its own numbers. Cascades, because a metric for
             | a target that no longer exists describes nothing.
             */
            $table->foreignId('post_target_id')
                ->constrained('post_targets')->cascadeOnDelete();
            $table->foreignId('social_account_id')
                ->constrained('social_accounts')->cascadeOnDelete();

            $table->string('provider_key', 40);

            /*
             | Nullable throughout. A network that does not report saves is
             | different from one reporting zero saves, and storing 0 for
             | "unknown" would quietly make an average wrong.
             */
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('reach')->nullable();
            $table->unsignedBigInteger('likes')->nullable();
            $table->unsignedBigInteger('comments')->nullable();
            $table->unsignedBigInteger('shares')->nullable();
            $table->unsignedBigInteger('saves')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('video_views')->nullable();

            /*
             | Engagement RATE is deliberately not stored. It is impressions
             | and interactions divided, both of which are here, and a stored
             | derivative goes stale the moment either side is corrected.
             */

            // Exactly what the provider returned, before we interpreted it.
            $table->json('raw')->nullable();

            /*
             | When the numbers describe, not when we wrote the row. A
             | collection that runs late must not make yesterday's figures look
             | like today's.
             */
            $table->timestamp('collected_at');

            $table->timestamps();

            /*
             | One row per target per collection moment. A retried collection
             | updates in place rather than doubling every figure on the
             | dashboard, which is the failure mode that makes analytics
             | untrustworthy rather than merely wrong.
             */
            $table->unique(['post_target_id', 'collected_at'], 'post_metrics_unique');

            // The dashboard's own query: a brand's numbers over a window.
            $table->index(['tenant_id', 'customer_id', 'collected_at']);
            $table->index(['social_account_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metrics');
    }
};
