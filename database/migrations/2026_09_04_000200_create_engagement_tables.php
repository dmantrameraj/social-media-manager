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
         | The unified inbox, deliberately its own tables.
         |
         | docs/12-ROADMAP.md §7 says engagement is "kept structurally separate
         | from publishing", and the reason is that the two have different
         | owners of truth. A post is ours until it is published; a
         | conversation is the PROVIDER's, always -- we hold a copy that is
         | already slightly out of date, and anything we send may be rejected,
         | edited or deleted on their side without telling us.
         |
         | Building this into the publishing tables would make every
         | conversation depend on a post existing, and most do not: a comment
         | can arrive on something published years ago, by another tool, or by
         | the client themselves.
         */
        Schema::create('inbox_threads', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('social_account_id')
                ->constrained('social_accounts')->cascadeOnDelete();

            $table->string('provider_key', 40);

            // comment | message. One inbox, two shapes.
            $table->string('kind', 20);

            /*
             | The provider's id for the conversation. This is the identity
             | that matters -- ours is a local convenience, and a re-sync must
             | update a thread rather than duplicate it.
             */
            $table->string('external_thread_id', 190);

            /*
             | Nullable, and nullable on purpose. When a comment is on
             | something we published, linking it is useful; when it is not,
             | the inbox still works. Making this required would be the
             | coupling the roadmap warns against.
             */
            $table->foreignId('post_target_id')->nullable()
                ->constrained('post_targets')->nullOnDelete();

            /*
             | Who is talking to us, as the provider describes them. No attempt
             | is made to resolve a real identity: platforms hand out opaque,
             | per-app ids, and inventing a person record from a display name
             | is how two customers become one.
             */
            $table->string('participant_name', 190)->nullable();
            $table->string('participant_external_id', 190)->nullable();

            $table->string('status', 20)->default('open');

            /*
             | Assignment is nullOnDelete: a departing colleague must not take
             | a client's conversation with them. It returns to unassigned and
             | stays in the queue.
             */
            $table->foreignId('assigned_to_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Sorting the queue, and knowing whether anyone has replied.
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['social_account_id', 'external_thread_id'],
                'inbox_threads_unique',
            );

            // The queue's own query: this brand's open threads, newest first.
            $table->index(['tenant_id', 'customer_id', 'status', 'last_message_at']);
            $table->index(['tenant_id', 'assigned_to_user_id', 'status']);
        });

        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inbox_thread_id')
                ->constrained('inbox_threads')->cascadeOnDelete();

            /*
             | Null for a message that has not reached the provider yet -- an
             | outbound reply is a local row first and acquires its external id
             | only once the provider accepts it.
             */
            $table->string('external_message_id', 190)->nullable();

            // inbound | outbound
            $table->string('direction', 20);

            /*
             | An internal note lives in the same thread as the conversation it
             | is about, exactly as PostComment does for a post. Keeping notes
             | in a separate table reliably produces notes nobody reads,
             | because they are not where the conversation is.
             */
            $table->boolean('is_internal')->default(false);

            /*
             | The ActorType discriminator for our own people; null for the
             | other side, whose identity belongs to the provider.
             */
            $table->string('author_type', 40)->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name', 190)->nullable();

            $table->text('body');

            /*
             | Outbound delivery is a state machine of its own: a reply can sit
             | pending, be sent, or be refused by the platform hours later. A
             | boolean would lose the difference between "not yet" and "never".
             */
            $table->string('delivery_status', 20)->default('delivered');
            $table->string('last_error_code', 80)->nullable();

            // When the provider says it happened, not when we stored it.
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            /*
             | Unique only where an external id exists: many local rows legally
             | have none (internal notes, unsent replies), and MySQL treats
             | each NULL as distinct, which is exactly what is wanted here.
             */
            $table->unique(
                ['inbox_thread_id', 'external_message_id'],
                'inbox_messages_unique',
            );

            $table->index(['inbox_thread_id', 'posted_at']);
            $table->index(['tenant_id', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('inbox_threads');
    }
};
