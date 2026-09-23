<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a news item came from.
 *
 * Three nullable columns, and nothing in the table changes meaning: a
 * hand-written article leaves all three NULL and behaves exactly as it does
 * today. Only ingested items carry them.
 *
 *   source_name  "iGaming Business"        — shown in the admin and credited
 *                                            on the public page
 *   source_url   the original article      — the outbound credit link
 *   source_ref   "igamingbusiness:477920"  — the dedup key
 *
 * ── Why source_ref matters more than it looks ───────────────────────────────
 *
 * Until now the ingestion pipeline had no column to key on, so it deduped on
 * the SLUG, derived from the source's headline. That worked, at two costs: the
 * public URL carried the source's wording rather than our own rewritten title,
 * and the slug became load-bearing — anything regenerating it would have made
 * the next run write a duplicate.
 *
 * With a real key both costs disappear. Dedup moves to `source_ref`, and the
 * rewriter is free to generate a slug from the ORIGINAL title it wrote, which
 * is what the URL should have said all along.
 *
 * The unique index is per SITE, like every other identity in this table: two
 * domains may each legitimately ingest the same story. MySQL treats NULLs as
 * distinct in a unique index, so any number of hand-written articles coexist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('articles', 'source_ref')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            // 191, not 255: this sits in a unique index alongside site_id, and
            // 191 is the longest a utf8mb4 column can be in one on older
            // InnoDB row formats. A "<key>:<numeric id>" never approaches it.
            $table->string('source_ref', 191)->nullable()->after('type');
            $table->string('source_name', 100)->nullable()->after('source_ref');
            $table->string('source_url', 500)->nullable()->after('source_name');

            $table->unique(['site_id', 'source_ref'], 'articles_site_source_ref_unique');

            // The admin's "where did this come from" filter. Cheap, and the
            // listing is the one place it is queried.
            $table->index(['site_id', 'type', 'source_name'], 'articles_site_type_source_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('articles', 'source_ref')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropUnique('articles_site_source_ref_unique');
            $table->dropIndex('articles_site_type_source_idx');
            $table->dropColumn(['source_ref', 'source_name', 'source_url']);
        });
    }
};
