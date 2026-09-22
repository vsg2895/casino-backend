<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus categories — one concept driving both the menu and the home page.
 *
 * "Bonus" becomes a parent in the header whose children are these categories,
 * and the home page grows one section per category. Because both are generated
 * from the same rows, adding a category adds its menu entry and its section
 * together, and they cannot drift apart the way a hand-maintained menu and a
 * hand-built page always eventually do.
 *
 * GLOBAL, not per-site — matching `categories`, which these sit beside
 * conceptually. A bonus type is a property of the offer, not of the domain
 * showing it. Per-site control is the `sites.bonus_enabled` flag added here: a
 * site either publishes the Bonus section or it does not.
 *
 * THE BACKFILL IS THE IMPORTANT PART. Every special offer that exists today is
 * moved into a "Special Offers" category, so the section that is on the home
 * page right now keeps exactly its current contents under its current name. The
 * change is additive from the reader's point of view: a heading appears above
 * it, and nothing that was listed stops being listed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bonus_categories')) {
            Schema::create('bonus_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                // Optional standfirst under the section heading. Null renders
                // nothing rather than an empty paragraph.
                $table->string('description', 500)->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                // The show/hide switch, same meaning as `active` everywhere else
                // in this schema. Off removes the menu entry AND the section.
                $table->boolean('active')->default(true);
                $table->timestamps();

                $table->index(['active', 'position']);
            });
        }

        if (! Schema::hasColumn('special_offers', 'bonus_category_id')) {
            Schema::table('special_offers', function (Blueprint $table): void {
                $table->foreignId('bonus_category_id')
                    ->nullable()
                    ->after('casino_id')
                    // nullOnDelete, not cascade: deleting a category must never
                    // delete the offers filed under it. They fall back to
                    // uncategorised and stay editable.
                    ->constrained('bonus_categories')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('sites', 'bonus_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->boolean('bonus_enabled')->default(false)->after('news_enabled');
            });
        }

        $this->backfill();
    }

    /**
     * Give every existing offer the home it already had.
     *
     * Idempotent: matched on slug, and offers are only moved when they have no
     * category yet, so a re-run cannot reshuffle an operator's later filing.
     */
    private function backfill(): void
    {
        $id = DB::table('bonus_categories')->where('slug', 'special-offers')->value('id');

        if ($id === null) {
            $id = DB::table('bonus_categories')->insertGetId([
                'name'        => 'Special Offers',
                'slug'        => 'special-offers',
                'description' => null,
                'position'    => 0,
                'active'      => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        DB::table('special_offers')->whereNull('bonus_category_id')->update(['bonus_category_id' => $id]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('special_offers', 'bonus_category_id')) {
            Schema::table('special_offers', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('bonus_category_id');
            });
        }

        if (Schema::hasColumn('sites', 'bonus_enabled')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropColumn('bonus_enabled');
            });
        }

        Schema::dropIfExists('bonus_categories');
    }
};
