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
         | Per-agency white labelling.
         |
         | BrandingResolver has said "tenant overrides are read from
         | branding_settings once that feature ships" since Phase 1, and every
         | template already goes through it. This is the table it was waiting
         | for; no Blade file changes.
         |
         | It matters most in the CLIENT PORTAL. A client of Bright Digital
         | logging in to approve their posts should see Bright Digital, not the
         | name of a SaaS vendor they have no relationship with -- that is the
         | whole product being sold, not decoration.
         */
        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();

            /*
             | One row per tenant. Unique rather than a plain index: two
             | branding rows for one agency is a state with no correct
             | resolution, and the database is the right place to make it
             | impossible.
             */
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            $table->string('app_name', 120)->nullable();
            $table->string('support_email', 190)->nullable();

            /*
             | Stored as hex and validated on the way in. These are
             | interpolated into a style attribute, so anything that is not a
             | colour is a CSS injection -- the value is checked at the
             | boundary rather than escaped at every use.
             */
            $table->string('primary_color', 7)->nullable();
            $table->string('secondary_color', 7)->nullable();

            $table->timestamps();

            /*
             | Declared separately, not chained onto foreignId(). ->unique()
             | returns the index fluent, so ->constrained() after it no longer
             | describes the column -- and the statement that produces blocks
             | rather than failing, which is a far worse way to find out.
             */
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
    }
};
