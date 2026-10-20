<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RevalidateNextJsSites;
use App\Models\CmsPage;
use App\Models\Site;
use App\Support\LegalPageContent;
use App\Support\SiteCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fills the three bracketed placeholders in a site's seeded legal pages.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * Registering a domain seeds eleven legal pages from {@see LegalPageContent},
 * and three facts in them are deliberately left blank because only a human can
 * supply them: the legal entity, its registered address, and the governing-law
 * jurisdiction. They appear thirteen times across three pages — the entity
 * alone is in Terms four times — so filling them by hand in the admin means
 * six page edits per brand, with the real risk of catching the first occurrence
 * on a page and missing the rest.
 *
 * ── What it will not do ─────────────────────────────────────────────────────
 *
 * It replaces PLACEHOLDERS ONLY. A page where somebody has already written a
 * real entity name contains no placeholder, so this command passes over it
 * untouched — it can never overwrite human-edited copy, and that is the point
 * of matching on the bracketed token rather than on a sentence pattern. The
 * same property makes it idempotent: a second run finds nothing and says so.
 *
 * It also makes no attempt to be clever about which pages carry which
 * placeholder. It scans every page the site owns and replaces what it finds,
 * so a future page that happens to use one is covered without editing this
 * file.
 *
 * ── Usage ───────────────────────────────────────────────────────────────────
 *
 *   php artisan sites:fill-legal-identity betpulsehq \
 *       --entity="BetPulseHQ Media Ltd" \
 *       --address="14 Maple Close, Bristol, BS7 8QR, United Kingdom" \
 *       --jurisdiction="England and Wales" \
 *       --dry-run
 *
 * Every option is independent: pass one, two or all three. Without `--dry-run`
 * it writes, flushes the site's backend cache and asks the site's Next.js app
 * to revalidate each page it changed — the same two steps an admin save
 * performs, so the public pages do not serve the old copy for an hour.
 */
class FillSiteLegalIdentity extends Command
{
    protected $signature = 'sites:fill-legal-identity
                            {site : Site slug or numeric id}
                            {--entity= : Registered legal entity, e.g. "BetPulseHQ Media Ltd"}
                            {--address= : Registered postal address, one line}
                            {--jurisdiction= : Governing law, e.g. "England and Wales"}
                            {--dry-run : Report what would change and write nothing}';

    protected $description = "Fill the legal entity, address and governing-law placeholders in a site's legal pages";

    public function handle(): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));

        if ($site === null) {
            $this->error('No site matches "' . $this->argument('site') . '".');

            return self::FAILURE;
        }

        /** @var array<string, string> $replacements placeholder => value */
        $replacements = array_filter([
            LegalPageContent::PLACEHOLDER_ENTITY       => $this->trimmedOption('entity'),
            LegalPageContent::PLACEHOLDER_ADDRESS      => $this->trimmedOption('address'),
            LegalPageContent::PLACEHOLDER_JURISDICTION => $this->trimmedOption('jurisdiction'),
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        if ($replacements === []) {
            // Refusing beats defaulting: a command that silently does nothing
            // when called wrongly is indistinguishable from one that ran and
            // found nothing to do.
            $this->error('Nothing to fill — pass at least one of --entity, --address or --jurisdiction.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf(
            '%s legal identity for %s (%s)',
            $dryRun ? 'Would fill' : 'Filling',
            $site->name,
            $site->slug,
        ));

        $pages = CmsPage::query()->where('site_id', $site->id)->orderBy('slug')->get();
        $rows = [];
        $changed = [];
        $total = 0;

        foreach ($pages as $page) {
            $content = (string) $page->content;
            $hits = [];

            foreach ($replacements as $placeholder => $value) {
                $count = substr_count($content, $placeholder);

                if ($count === 0) {
                    continue;
                }

                $hits[$placeholder] = $count;
                $content = str_replace($placeholder, $value, $content);
            }

            if ($hits === []) {
                continue;
            }

            $total += array_sum($hits);
            $rows[] = [
                $page->slug,
                implode(', ', array_map(
                    static fn (string $p, int $n): string => trim($p, '[]') . ' ×' . $n,
                    array_keys($hits),
                    $hits,
                )),
                (string) array_sum($hits),
            ];

            $changed[] = ['page' => $page, 'content' => $content];
        }

        if ($rows === []) {
            // The normal outcome of a second run, and of a site whose pages were
            // already completed by hand. Not an error.
            $this->line('  Nothing to fill — no placeholders found for the values given.');

            return self::SUCCESS;
        }

        $this->table(['Page', 'Placeholders', 'Total'], $rows);

        if ($dryRun) {
            $this->comment(sprintf('  Dry run — %d replacement(s) across %d page(s). Nothing written.', $total, count($rows)));

            return self::SUCCESS;
        }

        // One transaction: thirteen replacements across three pages either all
        // land or none do. A half-filled Terms page is worse than an untouched
        // one, because the brackets that remain look like the only ones left.
        try {
            DB::transaction(function () use ($changed): void {
                foreach ($changed as $entry) {
                    /** @var CmsPage $page */
                    $page = $entry['page'];
                    $page->content = $entry['content'];
                    $page->save();
                }
            });
        } catch (Throwable $e) {
            $this->error('Write failed, nothing was changed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->invalidate($site, array_map(static fn (array $e): string => (string) $e['page']->slug, $changed));

        $this->info(sprintf('  Filled %d placeholder(s) across %d page(s).', $total, count($rows)));

        return self::SUCCESS;
    }

    /**
     * Bust the backend cache and ask the site's Next.js app to re-render.
     *
     * The same two steps {@see \App\Http\Controllers\Api\Admin\CmsPageController}
     * performs after an admin save. Without the first, the public endpoint keeps
     * serving the old copy for up to an hour; without the second, the site's own
     * data cache does.
     *
     * Best-effort: the content is already written, so a failure here must not
     * fail the command — it is reported and the operator can flush by hand.
     *
     * @param  list<string>  $slugs
     */
    private function invalidate(Site $site, array $slugs): void
    {
        try {
            SiteCache::flushSite((int) $site->id);

            if ($site->active) {
                RevalidateNextJsSites::dispatch(
                    array_map(static fn (string $slug): string => 'page:' . $slug, array_values(array_unique($slugs))),
                    [$site->id],
                );
            }

            $this->line('  Cache flushed and revalidation queued.');
        } catch (Throwable $e) {
            $this->warn('  Content saved, but invalidation failed: ' . $e->getMessage());
            $this->warn('  Run `php artisan cache:clear` or re-save a page in the admin.');
        }
    }

    /** Accepts a slug or a numeric id, so the command is usable either way. */
    private function resolveSite(string $identifier): ?Site
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return ctype_digit($identifier)
            ? Site::query()->find((int) $identifier)
            : Site::query()->where('slug', $identifier)->first();
    }

    private function trimmedOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : null;
    }
}
