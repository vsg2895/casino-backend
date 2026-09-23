<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\News\NewsScrapeService;
use Illuminate\Console\Command;

/**
 * Takes the facts of recent stories from the configured feeds and files them as
 * drafts for an editor to approve.
 *
 * Writes NO original copy — that is `news:rewrite`, deliberately separate so a
 * re-run of this command cannot re-bill the rewriting of items already written.
 *
 *   php artisan news:scrape --dry-run
 *   php artisan news:scrape
 */
class ScrapeNews extends Command
{
    protected $signature = 'news:scrape
                            {--dry-run : Fetch and parse, reporting what would change, without writing}
                            {--source= : Limit the run to one source key from config/news.php}';

    protected $description = 'Collect news facts from the configured RSS feeds as unpublished drafts';

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

        $totals = $scraper->run($dryRun, function (string $line, string $level): void {
            match ($level) {
                'error' => $this->error($line),
                'warn'  => $this->warn($line),
                'info'  => $this->info($line),
                default => $this->line($line),
            };
        });

        $this->newLine();
        $this->info(sprintf(
            '%s%d new, %d skipped, %d failed.',
            $dryRun ? 'Would create: ' : '',
            $totals['created'],
            $totals['skipped'],
            $totals['failed'],
        ));

        if (! $dryRun && $totals['created'] > 0) {
            $this->line('All of them are drafts and hidden. Rewrite them with: php artisan news:rewrite');
        }

        // A run in which every source failed is a failure, even though the
        // individual items were handled gracefully — otherwise a feed that has
        // gone permanently 403 looks like a quiet day in cron's output.
        return $totals['failed'] > 0 && $totals['created'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
