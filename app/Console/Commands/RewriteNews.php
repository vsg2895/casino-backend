<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\News\NewsRewriteService;
use Illuminate\Console\Command;

/**
 * Turns scraped facts into this site's own copy.
 *
 * Separate from `news:scrape` so re-running the collector costs nothing: this
 * selects only articles with no body, and writing one is what marks it done.
 *
 *   php artisan news:rewrite --limit=5
 */
class RewriteNews extends Command
{
    protected $signature = 'news:rewrite
                            {--limit= : How many drafts to rewrite in this run}';

    protected $description = 'Write original copy for scraped news drafts using the Anthropic API';

    public function handle(NewsRewriteService $rewriter): int
    {
        $limit = (int) ($this->option('limit') ?: config('news.rewrite.batch', 10));

        if ($limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::FAILURE;
        }

        $this->line('Model: ' . config('news.rewrite.model') . ", up to {$limit} article(s).");

        $totals = $rewriter->run($limit, function (string $line, string $level): void {
            match ($level) {
                'error' => $this->error($line),
                'warn'  => $this->warn($line),
                'info'  => $this->info($line),
                default => $this->line($line),
            };
        });

        $this->newLine();
        $this->info("{$totals['rewritten']} rewritten, {$totals['failed']} failed.");

        if ($totals['rewritten'] > 0) {
            $this->line('Every one is still hidden. Approve them in the admin: Sites -> News.');
        }

        if ($totals['failed'] > 0) {
            $this->line('Failed drafts keep their facts and are retried on the next run.');
        }

        return self::SUCCESS;
    }
}
