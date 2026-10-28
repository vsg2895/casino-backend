<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\News\NewsRewriteService;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

/**
 * Turns scraped facts into this site's own copy.
 *
 * Separate from `news:scrape` so re-running the collector costs nothing: this
 * selects only ingested articles with no body, and writing one is what marks it
 * done. Nothing here publishes — every article stays hidden until an editor
 * switches it on.
 *
 *   php artisan news:rewrite --dry-run --limit=1
 *   php artisan news:rewrite --limit=10
 *   php artisan news:rewrite --id=42
 */
class RewriteNews extends Command
{
    protected $signature = 'news:rewrite
                            {--limit=10 : How many drafts to rewrite in this run}
                            {--id= : Rewrite this one article only}
                            {--dry-run : Call the model and run the checks, but write nothing}';

    protected $description = 'Write original copy for scraped news drafts using the Gemini API';

    public function handle(NewsRewriteService $rewriter): int
    {
        if (trim((string) config('news.rewrite.api_key')) === '') {
            // No silent no-op: "0 rewritten" would read like "nothing to do" and
            // hide the misconfiguration indefinitely.
            $this->error('GEMINI_API_KEY is not set (news.rewrite.api_key) — refusing to run. Add it to .env.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::FAILURE;
        }

        $id = $this->option('id');

        if ($id !== null && ! ctype_digit((string) $id)) {
            $this->error('--id must be an article id.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — the model is called, nothing is written.');
        }

        $pending = $rewriter->pending($limit, $id === null ? null : (int) $id);

        if ($pending->isEmpty()) {
            $this->info($id === null
                ? 'Nothing to rewrite.'
                : "Article #{$id} is not a scraped news draft waiting for a rewrite.");

            return self::SUCCESS;
        }

        $this->line('Model: ' . config('news.rewrite.model') . ", {$pending->count()} article(s).");

        $totals = ['rewritten' => 0, 'rejected' => 0, 'failed' => 0];

        foreach ($pending->values() as $i => $article) {
            if ($i > 0) {
                // The free tier's per-minute quota, not courtesy.
                Sleep::for((int) config('news.rewrite.delay_seconds', 5))->seconds();
            }

            $result = $rewriter->rewrite($article, $dryRun);
            $totals[$result['status']]++;

            match ($result['status']) {
                'rewritten' => $this->info("✓ #{$article->id} → {$result['slug']}"),
                'rejected'  => $this->warn("✗ #{$article->id} rejected: {$result['reason']}"),
                'failed'    => $this->error("! #{$article->id} failed: {$result['reason']}"),
            };
        }

        $this->newLine();
        $this->info(sprintf(
            '%s%d rewritten, %d rejected, %d failed.',
            $dryRun ? 'Would write: ' : '',
            $totals['rewritten'],
            $totals['rejected'],
            $totals['failed'],
        ));

        if (! $dryRun && $totals['rewritten'] > 0) {
            $this->line('Every one is still hidden. Approve them in the admin: Sites -> News.');
        }

        if ($totals['rejected'] + $totals['failed'] > 0) {
            $this->line('Rejected and failed drafts are unchanged and are retried on the next run.');
        }

        // Same rule as news:scrape: a run where nothing worked and something
        // broke is a failure, or a dead key looks like a quiet hour in cron.
        return $totals['rewritten'] === 0 && $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
