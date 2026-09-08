<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site URL redirects, applied by the front end.
 *
 * `docs/admin-first.md` lists this outright: a URL change is an editorial
 * action. Today a slug change silently 404s, which is why the project treats
 * slugs as unchangeable — this table is what makes changing one survivable.
 *
 * Site-scoped, because the six domains have different URL histories. A redirect
 * that makes sense on winpalack would be meaningless on another domain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redirects')) {
            return;
        }

        Schema::create('redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // Path only, always starting with "/". Storing a full URL would
            // invite redirects keyed on a host the site never sees, since the
            // front end matches on pathname.
            $table->string('source_path', 500);
            $table->string('destination_path', 500);

            // 301 permanent | 302 temporary. Stored rather than assumed: a
            // permanent redirect is cached hard by browsers and is effectively
            // irreversible for anyone who has already followed it, so choosing
            // it must be deliberate.
            $table->unsignedSmallInteger('status_code')->default(301);

            $table->boolean('active')->default(true);
            // Incremented on use, so an editor can see which redirects still
            // matter and which are dead weight from a migration years ago.
            $table->unsignedBigInteger('hits')->default(0);

            $table->timestamps();

            // One rule per path per site. Two rows claiming the same source is
            // a question with no correct answer, so the database refuses it.
            $table->unique(['site_id', 'source_path'], 'redirects_site_source_unique');
            // The lookup the public endpoint performs.
            $table->index(['site_id', 'active'], 'redirects_site_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
