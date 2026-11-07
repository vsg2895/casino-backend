<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\News\NewsScrapeService;
use Illuminate\Console\Command;

/**
 * Takes the facts of recent stories from the configured feeds and files them as
 * drafts for an editor to approve.
 *
 * Then hands the new drafts to `news:rewrite`, which writes original copy and
 * makes each one indexable when it clears NewsIndexGate. That command selects
 * only drafts with no body, so a re-run never re-bills items already written.
 * `--no-rewrite` stops after collecting; so does a missing GEMINI_API_KEY.
 *
 * Takes only the last day's items (`news.max_age_hours`), and the first run of
 * a day that brings any in deletes the site's oldest day of news, covers
 * included (`news.prune_oldest_day`) — a rolling window of recent news.
 *
 *   php artisan news:scrape --dry-run
 *   php artisan news:scrape
 *   php artisan news:scrape --no-prune
 *   php artisan news:scrape --no-rewrite
 */
class ScrapeNews extends Command
{
    protected $signature = 'news:scrape
                            {--dry-run : Fetch and parse, reporting what would change, without writing}
                            {--source= : Limit the run to one source key from config/news.php}
                            {--no-prune : Keep the oldest day of news instead of deleting it}
                            {--no-rewrite : Only collect; leave the drafts for news:rewrite}';

    protected $description = 'Collect news facts from the configured RSS feeds as unpublished drafts, then rewrite them';

    public function handle(NewsScrapeService $scraper): int
    {
        if (($only = trim((string) $this->option('source'))) !== '') {
            $sources = array_values(array_filter(
                config('news.sources', []),
                static fn (array $s) => ($s['key'] ?? null) === $only,
            ));

            if ($sources === []) {
                $this->error("No source with key \"{$only}\" in config/news.php.");

                return self::FAILURE;
            }

            // Forced on: naming a source explicitly is the operator enabling it
            // for this run, which is exactly how you test one in isolation.
            $sources[0]['enabled'] = true;
            config(['news.sources' => $sources]);
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        $totals = $scraper->run($dryRun, prune: ! $this->option('no-prune'), report: function (string $line, string $level): void {
            match ($level) {
                'error' => $this->error($line),
                'warn'  => $this->warn($line),
                'info'  => $this->info($line),
                default => $this->line($line),
            };
        });

        $this->newLine();
        $this->info(sprintf(
            '%s%d new, %d skipped, %d failed, %d old deleted.',
            $dryRun ? 'Would create: ' : '',
            $totals['created'],
            $totals['skipped'],
            $totals['failed'],
            $totals['pruned'],
        ));

        if (! $dryRun && $totals['created'] > 0) {
            $this->rewriteNewDrafts($totals['created']);
        }

        // A run in which every source failed is a failure, even though the
        // individual items were handled gracefully — otherwise a feed that has
        // gone permanently 403 looks like a quiet day in cron's output.
        return $totals['failed'] > 0 && $totals['created'] === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Rewrite what this run filed, through `news:rewrite` itself so the quota,
     * the checks, the delay and the report are the same as a run by hand. Its
     * outcome never fails the scrape: what it could not write is retried by
     * the next `news:rewrite`.
     */
    private function rewriteNewDrafts(int $created): void
    {
        if ($this->option('no-rewrite')) {
            $this->line('Drafts are hidden and unwritten. Rewrite them with: php artisan news:rewrite');

            return;
        }

        if (trim((string) config('news.rewrite.api_key')) === '') {
            $this->warn('GEMINI_API_KEY is not set — the drafts were not rewritten. Set it, then run: php artisan news:rewrite');

            return;
        }

        $this->newLine();
        $this->line('Rewriting the new drafts…');
        // At least the usual batch, so drafts a capped or failed run left
        // behind are picked up too; newest first, so this run's come first.
        $this->call('news:rewrite', ['--limit' => (string) max(10, $created)]);
    }
}
