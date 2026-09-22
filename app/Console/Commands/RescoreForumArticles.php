<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ForumArticle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Materialises the Hot Threads ranking into `forum_articles.hot_score`.
 *
 * ── Why a stored column ─────────────────────────────────────────────────────
 *
 * The rank is an expression: views × 1 + recent replies × 25. An ORDER BY over
 * an expression cannot use an index and ALWAYS filesorts, no matter how it is
 * written — there is no index that makes an arbitrary computation orderable. So
 * the value is computed here and stored where `forum_articles_hot_idx` can
 * serve it.
 *
 * ── Why replies are weighted 25× a view ─────────────────────────────────────
 *
 * A view is one HTTP request and can be manufactured by anyone with a loop. A
 * reply has to survive moderation and be written by an account that cleared
 * pre-moderation. Ranking them equally would make "hot" mean "most crawled".
 *
 * ── The window ──────────────────────────────────────────────────────────────
 *
 * Only replies from the last 7 days count, so a thread that was busy two years
 * ago sinks. Views are lifetime, because there is no per-day view history and
 * inventing one to rank a tab is not worth a second table.
 *
 * Runs in chunks inside short transactions: safe on production, and re-running
 * it is a no-op beyond refreshing the numbers.
 *
 *   php artisan forum:rescore
 */
class RescoreForumArticles extends Command
{
    protected $signature = 'forum:rescore {--chunk=500 : Articles per chunk}';

    protected $description = 'Recompute the Hot Threads score for every published forum article';

    public function handle(): int
    {
        $since = now()->subDays(ForumArticle::HOT_WINDOW_DAYS);
        $chunk = max(50, (int) $this->option('chunk'));
        $touched = 0;

        ForumArticle::query()
            ->where('status', ForumArticle::STATUS_PUBLISHED)
            ->orderBy('id')
            ->chunkById($chunk, function ($articles) use ($since, &$touched): void {
                $ids = $articles->pluck('id')->all();

                // Recent approved replies per article — one grouped query per
                // chunk, served by forum_posts_thread_idx.
                $recent = DB::table('forum_posts')
                    ->whereIn('forum_article_id', $ids)
                    ->where('status', 'approved')
                    ->whereNull('deleted_at')
                    ->where('created_at', '>=', $since)
                    ->groupBy('forum_article_id')
                    ->selectRaw('forum_article_id, COUNT(*) AS c')
                    ->pluck('c', 'forum_article_id');

                foreach ($articles as $article) {
                    $score = (int) $article->views_count * ForumArticle::HOT_VIEW_WEIGHT
                        + (int) ($recent[$article->id] ?? 0) * ForumArticle::HOT_REPLY_WEIGHT;

                    // UNSIGNED INT caps at ~4.29 billion. A runaway view count
                    // must saturate rather than wrap, because a wrapped score
                    // would rank a dead thread first.
                    $score = min($score, 4_294_967_295);

                    if ((int) $article->hot_score === $score) {
                        continue;
                    }

                    ForumArticle::query()->whereKey($article->id)->update(['hot_score' => $score]);
                    $touched++;
                }
            });

        $this->info($touched === 0 ? 'Every score already current.' : "Rescored {$touched} article(s).");

        return self::SUCCESS;
    }
}
