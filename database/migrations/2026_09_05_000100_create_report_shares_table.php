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
         | A read-only report link an agency can send to a client.
         |
         | This is the only unauthenticated surface in the product that shows a
         | tenant's data, so every column here exists to bound what a leaked
         | link can do.
         */
        Schema::create('report_shares', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();

            /*
             | Only the hash is stored, exactly as oauth_states does for its
             | single-use tokens: a database read -- a backup, a support query,
             | a leaked dump -- must not yield a working link.
             */
            $table->char('token_hash', 64)->unique();

            /*
             | The window is FROZEN at creation rather than computed on view.
             |
             | "Last 30 days" evaluated at view time means a link sent in
             | January quietly shows April's numbers, and the client reads a
             | report nobody at the agency ever saw. A shared report is a
             | statement about a period, so the period is part of the link.
             */
            $table->timestamp('window_from');
            $table->timestamp('window_to');

            /*
             | Required, not nullable. A share link that never expires is a
             | permanent unauthenticated view of a client's performance, and
             | nobody remembers to clean those up.
             */
            $table->timestamp('expires_at');

            // Revocation is separate from expiry: one is a decision, the other
            // is a deadline, and an agency needs to be able to make the first
            // without waiting for the second.
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             | Enough to answer "did the client ever open it?" without building
             | an access log. A full log of an unauthenticated endpoint is a
             | privacy question of its own.
             */
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_shares');
    }
};
