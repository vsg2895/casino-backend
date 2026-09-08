<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable navigation, per site.
 *
 * The header and footer menus are currently arrays in each site's `layout.tsx`,
 * which makes adding a menu item a deploy. `docs/admin-first.md` names
 * navigation explicitly: header menu, footer columns and ordering are records,
 * not code.
 *
 * WHAT IS DELIBERATELY NOT HERE: the legal/compliance row (T&Cs, privacy, 18+,
 * responsible gambling). Those stay in the site's own code, because the contract
 * says the PRESENCE of compliance markup is code even though its text is
 * content. Keeping them out of this table is what makes them undeletable — a nav
 * table that let an editor remove the responsible-gambling link would be a
 * compliance regression shipped as a feature.
 *
 * An empty table is a valid, expected state: a site with no rows falls back to
 * the links its code already declares, so this migration changes nothing on any
 * site until someone adds a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nav_items')) {
            return;
        }

        Schema::create('nav_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // 'header' | 'footer'. Two menus, one table: they carry identical
            // fields, and splitting them would duplicate every screen.
            $table->string('location', 10);

            $table->string('label', 80);
            // An internal path ('/casinos') or an absolute URL. Stored as typed;
            // the front end decides between <Link> and <a> by looking for a
            // scheme, so an editor never has to know the difference.
            $table->string('url', 500);

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            // Only meaningful for absolute URLs; an internal link opening a new
            // tab is a usability bug, not a feature.
            $table->boolean('opens_in_new_tab')->default(false);

            $table->timestamps();

            // The public read: this site's menu, in order. Ordered to match so
            // the query needs no filesort.
            $table->index(['site_id', 'location', 'active', 'position'], 'nav_items_public_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nav_items');
    }
};
