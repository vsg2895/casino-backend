<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\Category;
use App\Models\CmsPage;
use App\Models\SearchIndexEntry;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Services\Search\SearchIndexer;
use Illuminate\Console\Command;

/**
 * Rebuilds `search_index` from the entity tables.
 *
 * `search_index` is a cache, so this is always safe to run: every row it writes
 * is derived, and anything it cannot derive is content that is not publicly
 * visible and must not be searchable anyway.
 *
 * CHUNKED throughout. Reviews will outgrow every other table indexed here, so
 * nothing loads a whole table into memory — casinos and offers walk in chunks,
 * reviews stream with a cursor.
 *
 * Safe on production: it never truncates. Stale rows disappear because each
 * entity's sync deletes the sites it is no longer visible on, so a full run
 * converges on the correct state without a window where search returns nothing.
 * `--prune` additionally clears rows whose entity has vanished entirely.
 *
 *   php artisan search:reindex
 *   php artisan search:reindex --section=forum
 *   php artisan search:reindex --site=winpalack --prune
 */
class ReindexSearch extends Command
{
    protected $signature = 'search:reindex
                            {--site= : Limit the report to one site (slug or id); indexing itself is global}
                            {--section= : Only this section (casinos, special_offers, categories, pages, forum)}
                            {--chunk=200 : Rows per chunk}
                            {--prune : Also delete rows whose source entity no longer exists}';

    protected $description = 'Rebuild the site search index from the source tables';

    public function handle(SearchIndexer $indexer): int
    {
        $section = $this->option('section');
        $sections = array_keys((array) config('search.sections', []));

        if ($section !== null && ! in_array($section, $sections, true)) {
            $this->error("Unknown section '{$section}'. Known: " . implode(', ', $sections));

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $only = static fn (string $key): bool => $section === null || $section === $key;

        // ORDER MATTERS. Casinos first: syncCasino() cascades into offers,
        // reviews and categories, so by the time those sections run on their own
        // most rows are already correct and the passes are cheap confirmations.
        if ($only('casinos')) {
            // withTrashed: a soft-deleted casino must be visited so its rows
            // (and its offers' and reviews') are cleared, not merely skipped.
            $this->walk('casinos', Casino::withTrashed(), $chunk,
                fn (Casino $m) => $indexer->syncCasino($m));
        }

        if ($only('special_offers')) {
            $this->walk('special_offers', SpecialOffer::withTrashed(), $chunk,
                fn (SpecialOffer $m) => $indexer->syncSpecialOffer($m));
        }

        if ($only('categories')) {
            $this->walk('categories', Category::query(), $chunk,
                fn (Category $m) => $indexer->syncCategory($m));
        }

        if ($only('pages')) {
            $this->walk('pages', CmsPage::query(), $chunk,
                fn (CmsPage $m) => $indexer->syncPage($m));
        }

        if ($only('forum')) {
            $this->walk('forum', CasinoReview::query(), $chunk,
                fn (CasinoReview $m) => $indexer->syncReview($m));
        }

        if ($this->option('prune')) {
            $this->prune();
        }

        $this->newLine();
        $this->report();

        return self::SUCCESS;
    }

    /**
     * Walk a table in chunks, syncing each row.
     *
     * chunkById, not chunk: the sync writes to a different table but an ordinary
     * OFFSET chunk can still skip rows if anything shifts the ordering mid-run.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     */
    private function walk(string $label, $query, int $chunk, callable $sync): void
    {
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->line("  <fg=gray>{$label}: nothing to index</>");

            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat("  {$label}: %current%/%max% [%bar%] %elapsed%");
        $bar->start();

        $query->chunkById($chunk, function ($rows) use ($sync, $bar): void {
            foreach ($rows as $row) {
                $sync($row);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
    }

    /**
     * Delete rows whose source entity is gone.
     *
     * Only reachable via --prune, because it is the one operation here that can
     * remove a row the syncs would not: a hard-deleted entity leaves no model to
     * iterate, so nothing else notices it.
     */
    private function prune(): void
    {
        $map = [
            Casino::class       => 'casinos',
            SpecialOffer::class => 'special_offers',
            Category::class     => 'categories',
            CmsPage::class      => 'cms_pages',
            CasinoReview::class => 'casino_reviews',
        ];

        $removed = 0;

        foreach ($map as $class => $table) {
            $removed += SearchIndexEntry::query()
                ->where('searchable_type', $class)
                ->whereNotIn('searchable_id', fn ($q) => $q->from($table)->select('id'))
                ->delete();
        }

        $this->line("  <fg=yellow>pruned {$removed} orphaned row(s)</>");
    }

    /** Row counts per section, for the site named by --site or for all sites. */
    private function report(): void
    {
        $siteOption = $this->option('site');
        $query = SearchIndexEntry::query();
        $scope = 'all sites';

        if ($siteOption !== null) {
            $site = Site::query()
                ->where('slug', $siteOption)
                ->orWhere('id', is_numeric($siteOption) ? (int) $siteOption : 0)
                ->first();

            if ($site === null) {
                $this->warn("No site matches '{$siteOption}' — reporting all sites instead.");
            } else {
                $query->where('site_id', $site->id);
                $scope = $site->slug;
            }
        }

        $rows = (clone $query)
            ->selectRaw('section, COUNT(*) as aggregate')
            ->groupBy('section')
            ->pluck('aggregate', 'section');

        $this->info("Indexed rows ({$scope}):");

        foreach (array_keys((array) config('search.sections', [])) as $key) {
            $this->line(sprintf('  %-16s %d', $key, (int) ($rows[$key] ?? 0)));
        }

        $this->line(sprintf('  %-16s %d', 'TOTAL', (int) (clone $query)->count()));
    }
}
