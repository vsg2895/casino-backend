<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The denormalised index behind the site-search overlay.
 *
 * One row per (site, entity). Everything needed to render a suggestion row is
 * stored here — title, subtitle, url, image — so a keystroke costs ONE indexed
 * query with no joins, instead of a five-way UNION whose branches scope to a
 * site three different ways.
 *
 * It is a CACHE, not a source of truth: every row is reproducible from the
 * entity tables by `search:reindex`, so it can be truncated and rebuilt at any
 * time without data loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_index', function (Blueprint $table): void {
            $table->id();

            // Pre-scoped: rows are written per site, so no query ever has to
            // work out visibility at read time.
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();

            // Morph target, so the observers can address a row by its entity
            // without a per-section branch.
            $table->string('searchable_type', 191);
            $table->unsignedBigInteger('searchable_id');

            $table->string('section', 32);

            $table->string('title');
            // Category name, or the casino a review belongs to.
            $table->string('subtitle')->nullable();
            // Secondary match target: description / review excerpt, truncated.
            $table->text('body')->nullable();

            $table->string('slug');
            // The resolved public path, computed at index time. Storing it means
            // the API never rebuilds URLs per result, and a route change is a
            // reindex rather than a code path in the hot query.
            $table->string('url', 500);
            // The entity's stored image path, NOT an absolute URL — the same
            // relative value every other public endpoint returns, so the front
            // end resolves it with its existing resolveImageUrl() helper.
            $table->string('image_url', 500)->nullable();

            $table->unsignedTinyInteger('weight')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Idempotent upserts: an observer firing twice must update, never
            // duplicate. Also the natural key the indexer deletes by.
            $table->unique(
                ['site_id', 'searchable_type', 'searchable_id'],
                'search_index_entity_unique',
            );

            // Section counts for the pills, and single-section listings. Ordered
            // site → is_active → section because every query filters the first
            // two and only sometimes the third; `weight` rides along so the
            // ordering can be served from the index.
            $table->index(
                ['site_id', 'is_active', 'section', 'weight'],
                'search_index_scope_idx',
            );
        });

        // FULLTEXT over the two matchable columns. The primary access path:
        // MATCH(title, body) AGAINST ('gam*' IN BOOLEAN MODE).
        //
        // Both columns in ONE index, not two — MySQL requires the MATCH() column
        // list to correspond exactly to a single FULLTEXT index, so separate
        // indexes on title and body could not serve this query at all.
        // MySQL only. The test harness runs on SQLite, which has neither
        // FULLTEXT nor MATCH() — SearchService detects that and serves every
        // query through its prefix path there. Production is MySQL.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE `search_index` ADD FULLTEXT `search_index_fulltext` (`title`, `body`)');

        // Prefix-friendly index for the SHORT-QUERY path. Queries below
        // innodb_ft_min_token_size get nothing from FULLTEXT, so they fall back
        // to LIKE 'x%' — which is only sane because it is left-anchored and can
        // therefore range-scan this index. (A leading %wildcard could not, and
        // is banned outright.)
        //
        // Raw SQL because Laravel's schema builder cannot express a prefix
        // length. 64 chars keeps the key well inside InnoDB's 3072-byte limit at
        // utf8mb4 (4 bytes/char: 8 + 1 + 256 = 265 bytes).
        DB::statement(
            'ALTER TABLE `search_index` ADD INDEX `search_index_title_prefix_idx` (`site_id`, `is_active`, `title`(64))'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index');
    }
};
