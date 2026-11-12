<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Casino;
use App\Jobs\RevalidateNextJsSites;
use App\Models\CasinoSiteContent;
use App\Support\SiteCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Writes the editorial SEO content — `description`, `meta_title`,
 * `meta_description` — for the casinos live on production
 * (database/seeders/data/casino_seo_content.json).
 *
 * Each text was written from that casino's verified operator profile on
 * winpalack.com (licence, operator, banking, limits, providers, support), so it
 * states facts the review page itself shows. Bonus amounts and star ratings are
 * left out on purpose: both change in the admin, and a meta tag that quotes them
 * goes stale silently.
 *
 * The values go into the casino's DEFAULT columns, which every site falls back
 * to, so all sites show the same text. Nothing is written to
 * `casino_site_contents`: a per-site override is a person's own copy, and one
 * that already exists keeps winning on its site — the seeder lists those rather
 * than touching them.
 *
 * Production-safe:
 *  - matched on `slug` (ids differ between databases); a slug that is missing
 *    here is reported and skipped, never created;
 *  - the length budgets are checked before anything is written;
 *  - the values it replaces are saved first to
 *    storage/app/seo-backups/casino-seo-content-<time>.json;
 *  - saved quietly, then each affected site's cache is flushed and its Next.js
 *    app revalidated ONCE — what CasinoObserver does per save. Revalidation is
 *    synchronous with a 5-second timeout per site, so letting the observer run
 *    would ping every site once per casino: minutes if a site is down;
 *  - re-running it is a no-op: an unchanged casino is not saved again.
 *
 * Not part of DatabaseSeeder. Run once after deploy:
 *   php artisan db:seed --class=CasinoSeoContentSeeder --force
 */
class CasinoSeoContentSeeder extends Seeder
{
    /**
     * Same budgets as `casinos:seo-meta`: Google cuts a title near 60 characters
     * and a description near 160, and the sites append " | <Brand>" (up to 19)
     * and a review signature (up to 36).
     */
    private const int MAX_TITLE = 41;
    private const int MAX_DESCRIPTION = 124;

    private const array FIELDS = ['description', 'meta_title', 'meta_description'];

    public function run(): void
    {
        $content = $this->content();

        $casinos = Casino::query()
            ->whereIn('slug', array_map('strval', array_keys($content)))
            ->get()
            ->keyBy('slug');

        $missing = array_diff(array_map('strval', array_keys($content)), $casinos->keys()->all());
        foreach ($missing as $slug) {
            $this->command->warn("No casino with slug '{$slug}' in this database — skipped.");
        }

        $changed = $casinos->filter(function (Casino $casino) use ($content): bool {
            foreach (self::FIELDS as $field) {
                if ((string) $casino->{$field} !== $content[$casino->slug][$field]) {
                    return true;
                }
            }

            return false;
        });

        if ($changed->isEmpty()) {
            $this->command->info('Every casino already carries this content. Nothing to write.');

            return;
        }

        $this->command->info('Previous values saved to ' . $this->backUp($changed->all()));

        foreach ($changed as $casino) {
            $casino->fill(array_intersect_key($content[$casino->slug], array_flip(self::FIELDS)));
            $casino->saveQuietly();
        }

        $this->refreshSites($changed->pluck('id')->all());

        $this->command->info(sprintf(
            'SEO content written to %d casino(s); %d already up to date.',
            $changed->count(),
            $casinos->count() - $changed->count(),
        ));

        $this->reportOverrides($casinos->pluck('id')->all());
    }

    /**
     * The data file, validated in full before anything is written.
     *
     * @return array<string, array{description: string, meta_title: string, meta_description: string}>
     */
    private function content(): array
    {
        $rows = json_decode(File::get(database_path('seeders/data/casino_seo_content.json')), true, flags: JSON_THROW_ON_ERROR);
        $problems = [];

        foreach ($rows as $slug => $row) {
            foreach (self::FIELDS as $field) {
                if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                    $problems[] = "{$slug}: {$field} is missing";
                }
            }

            if (mb_strlen((string) ($row['meta_title'] ?? '')) > self::MAX_TITLE) {
                $problems[] = "{$slug}: meta_title is over " . self::MAX_TITLE . ' characters';
            }

            if (mb_strlen((string) ($row['meta_description'] ?? '')) > self::MAX_DESCRIPTION) {
                $problems[] = "{$slug}: meta_description is over " . self::MAX_DESCRIPTION . ' characters';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException("casino_seo_content.json is invalid — nothing was written:\n" . implode("\n", $problems));
        }

        return $rows;
    }

    /**
     * Save what is about to be replaced, so a run can be undone by hand.
     *
     * @param  array<int|string, Casino>  $casinos
     */
    private function backUp(array $casinos): string
    {
        $backup = [];
        foreach ($casinos as $casino) {
            $backup[$casino->slug] = $casino->only(self::FIELDS);
        }

        $path = storage_path('app/seo-backups/casino-seo-content-' . now()->format('Ymd-His') . '.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * CasinoObserver's invalidation, once for the whole run.
     *
     * @param  list<int>  $casinoIds
     */
    private function refreshSites(array $casinoIds): void
    {
        $siteIds = Casino::query()
            ->whereIn('id', $casinoIds)
            ->with('sites:id')
            ->get()
            ->flatMap(static fn (Casino $casino) => $casino->sites->pluck('id'))
            ->unique()
            ->values()
            ->all();

        if ($siteIds === []) {
            return;
        }

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite((int) $siteId);
        }

        RevalidateNextJsSites::dispatch(['casinos'], $siteIds);
        $this->command->info('Cache flushed and revalidation sent to ' . count($siteIds) . ' site(s).');
    }

    /**
     * Per-site overrides still beat the default on their own site. List them,
     * so it is clear where the new text will NOT show.
     *
     * @param  list<int>  $casinoIds
     */
    private function reportOverrides(array $casinoIds): void
    {
        $overrides = CasinoSiteContent::query()
            ->whereIn('casino_id', $casinoIds)
            ->where(static function ($query): void {
                $query->whereNotNull('description')
                    ->orWhereNotNull('meta_title')
                    ->orWhereNotNull('meta_description');
            })
            ->with(['casino:id,slug', 'site:id,slug'])
            ->get();

        foreach ($overrides as $override) {
            $this->command->warn(sprintf(
                'Site %s has its own text for %s — that override still wins there (admin → Casino → Details → that site).',
                $override->site?->slug,
                $override->casino?->slug,
            ));
        }
    }
}
