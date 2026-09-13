<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring posts, as specified in docs/06-PUBLISHING-ENGINE.md §11.
 *
 * A rule is a TEMPLATE plus a cadence. It never publishes anything itself: a
 * materialiser turns it into concrete posts a bounded window ahead, and those
 * posts go through the same workflow, approval gate and plan limit as
 * everything else. The rule is not a second publishing path.
 *
 * RRULE-SHAPED, NOT AN RRULE. The columns carry the parts of RFC 5545 an
 * agency actually uses -- a frequency, an interval, and which days -- because
 * parsing the full grammar means supporting BYSETPOS and leap-second edge
 * cases nobody asked for, and the stored form should be readable in a database
 * client by whoever is debugging a post that went out on the wrong day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_post_rules', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('name', 190);

            // The template the generated posts are cut from.
            $table->string('title', 190)->nullable();
            $table->text('body');

            /*
             | The cadence. `interval` is "every N of these": weekly with an
             | interval of 2 is fortnightly.
             */
            $table->string('frequency', 20);
            $table->unsignedSmallInteger('interval')->default(1);

            /*
             | Weekly rules only: which days, as ISO-8601 numbers (1 = Monday).
             | Null for daily and monthly, where the day is implied or comes
             | from day_of_month.
             */
            $table->json('weekdays')->nullable();

            // Monthly rules only. Clamped to the last day of a short month,
            // so a 31st rule still fires in February rather than skipping it.
            $table->unsignedTinyInteger('day_of_month')->nullable();

            /*
             | Wall-clock time in the rule's own timezone, snapshotted from the
             | brand the same way a post snapshots it. A brand that later moves
             | zones must not silently shift every future occurrence.
             */
            $table->time('time_of_day');
            $table->string('timezone', 64);

            $table->date('starts_on');

            // Null means "until somebody stops it". The horizon is what keeps
            // that from meaning infinite rows.
            $table->date('ends_on')->nullable();

            $table->boolean('is_active')->default(true);

            /*
             | How far this rule has been materialised. The materialiser
             | resumes from here rather than recomputing from starts_on, which
             | is what stops a rule that has run for a year from rebuilding a
             | year of occurrences on every pass.
             */
            $table->date('materialised_through')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['customer_id', 'is_active']);
        });

        /*
         | Where each occurrence publishes. A pivot rather than a JSON column of
         | ids: an account that is disconnected and deleted should take its rows
         | with it, and a foreign key is the only thing that guarantees that.
         */
        Schema::create('recurring_post_rule_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_post_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();

            $table->unique(
                ['recurring_post_rule_id', 'social_account_id'],
                'recurring_rule_account_unique',
            );
        });

        Schema::table('posts', function (Blueprint $table) {
            /*
             | Which rule produced this post, and for which occurrence.
             |
             | The date is what makes materialising idempotent: a rule plus an
             | occurrence date identifies a post exactly once, so a command that
             | runs twice -- or a horizon that moves -- cannot create the same
             | post again.
             |
             | nullOnDelete: deleting a rule must not delete the posts it has
             | already produced. Those are real content, some of it published.
             */
            $table->foreignId('recurring_post_rule_id')->nullable()->after('customer_id')
                ->constrained()->nullOnDelete();

            $table->date('occurrence_date')->nullable()->after('recurring_post_rule_id');

            $table->unique(
                ['recurring_post_rule_id', 'occurrence_date'],
                'posts_recurrence_occurrence_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique('posts_recurrence_occurrence_unique');
            $table->dropConstrainedForeignId('recurring_post_rule_id');
            $table->dropColumn('occurrence_date');
        });

        Schema::dropIfExists('recurring_post_rule_accounts');
        Schema::dropIfExists('recurring_post_rules');
    }
};
