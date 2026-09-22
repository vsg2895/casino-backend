<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumUser;
use App\Models\Site;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds every denormalised forum counter from the source rows.
 *
 * The observers keep the totals correct in normal operation. This command is
 * what makes that safe to rely on: a repair that can be run at any time, so a
 * drift caused by a direct SQL edit, an interrupted deploy or a bug in a future
 * observer is a five-minute fix rather than an archaeology project.
 *
 * ── Safe on production ──────────────────────────────────────────────────────
 *
 * Chunked, never loads a table, and writes each row inside its own short
 * transaction so it never holds a long lock. It is also idempotent and
 * convergent: running it twice changes nothing the second time, and running it
 * while members are posting is fine — the observers keep working, and anything
 * written mid-run is simply counted by the next run.
 *
 * Counters are rebuilt bottom-up, because each level is derived from the one
 * below it: posts → articles → categories → members.
 *
 *   php artisan forum:recount
 *   php artisan forum:recount --site=winpalack
 *   php artisan forum:recount --dry-run
 */
class RecountForum extends Command
{
    protected $signature = 'forum:recount
                            {--site= : Limit to one site (slug or id)}
                            {--chunk=500 : Rows per chunk}
                            {--dry-run : Report the drift without writing anything}';

    protected $description = 'Recalculate forum article, category and member counters from scratch';

    public function handle(): int
    {
        $siteId = $this->resolveSiteId();

        if ($siteId === false) {
            return self::FAILURE;
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $dry = (bool) $this->option('dry-run');

        if ($dry) {
            $this->warn('Dry run — nothing will be written.');
        }

        $drift = 0;
        $drift += $this->recountArticles($siteId, $chunk, $dry);
        $drift += $this->recountCategories($siteId, $chunk, $dry);
        $drift += $this->recountMembers($siteId, $chunk, $dry);

        $this->newLine();
        $this->info($drift === 0
            ? 'All counters already correct.'
            : ($dry ? "{$drift} counter(s) are wrong." : "Corrected {$drift} counter(s)."));

        return self::SUCCESS;
    }

    /**
     * Articles: post totals and the last-post pointer.
     *
     * This is the ONLY level that reads `forum_posts`, and it does so with one
     * grouped aggregate per chunk of articles rather than a query per article.
     */
    private function recountArticles(?int $siteId, int $chunk, bool $dry): int
    {
        $drift = 0;

        $query = ForumArticle::withTrashed()
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('id');

        $query->chunkById($chunk, function ($articles) use (&$drift, $dry): void {
            $ids = $articles->pluck('id')->all();

            // One aggregate for the whole chunk. Grouped on the leading column
            // of forum_posts_thread_idx, so it is an index scan per article.
            $totals = ForumPost::query()
                ->whereIn('forum_article_id', $ids)
                ->where('status', ForumPost::STATUS_APPROVED)
                ->groupBy('forum_article_id')
                ->selectRaw('forum_article_id, COUNT(*) AS c, MAX(id) AS last_id')
                ->get()
                ->keyBy('forum_article_id');

            // The pointer needs the author and time of that MAX(id), which the
            // aggregate cannot carry — one keyed lookup for the chunk.
            $lastPosts = ForumPost::query()
                ->whereIn('id', $totals->pluck('last_id')->filter()->all())
                ->get(['id', 'forum_user_id', 'created_at'])
                ->keyBy('id');

            foreach ($articles as $article) {
                $row = $totals->get($article->id);
                $count = (int) ($row->c ?? 0);
                $last = $row?->last_id ? $lastPosts->get($row->last_id) : null;

                $correct = [
                    'posts_count'       => $count,
                    'last_post_id'      => $last?->id,
                    'last_post_at'      => $last?->created_at,
                    'last_post_user_id' => $last?->forum_user_id,
                ];

                if (! $this->differs($article, $correct)) {
                    continue;
                }

                $drift++;
                $this->line("  article #{$article->id} posts_count {$article->posts_count} → {$count}");

                if (! $dry) {
                    DB::transaction(fn () => ForumArticle::withTrashed()->whereKey($article->id)->update($correct));
                }
            }
        });

        return $drift;
    }

    /**
     * Categories: summed from `forum_articles`, never from `forum_posts`.
     *
     * Correct only because recountArticles() ran first — which is why the order
     * in handle() is not arbitrary.
     */
    private function recountCategories(?int $siteId, int $chunk, bool $dry): int
    {
        $drift = 0;

        ForumCategory::query()
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('id')
            ->chunkById($chunk, function ($categories) use (&$drift, $dry): void {
                foreach ($categories as $category) {
                    $totals = ForumArticle::query()
                        ->where('forum_category_id', $category->id)
                        ->selectRaw('COUNT(*) AS articles_count, COALESCE(SUM(posts_count), 0) AS posts_count')
                        ->first();

                    $last = ForumArticle::query()
                        ->where('forum_category_id', $category->id)
                        ->whereNotNull('last_post_id')
                        ->orderByDesc('last_post_at')
                        ->orderByDesc('last_post_id')
                        ->first(['id', 'last_post_id', 'last_post_at', 'last_post_user_id']);

                    $correct = [
                        'articles_count'       => (int) ($totals->articles_count ?? 0),
                        'posts_count'          => (int) ($totals->posts_count ?? 0),
                        'last_post_id'         => $last?->last_post_id,
                        'last_post_at'         => $last?->last_post_at,
                        'last_post_user_id'    => $last?->last_post_user_id,
                        'last_post_article_id' => $last?->id,
                    ];

                    if (! $this->differs($category, $correct)) {
                        continue;
                    }

                    $drift++;
                    $this->line("  category #{$category->id} posts_count {$category->posts_count} → {$correct['posts_count']}");

                    if (! $dry) {
                        DB::transaction(fn () => ForumCategory::whereKey($category->id)->update($correct));
                    }
                }
            });

        return $drift;
    }

    /**
     * Members: total posts written, and how many were accepted.
     *
     * `approved_posts_count` is what pre-moderation and link permission read, so
     * a drift here is a security-adjacent bug, not a cosmetic one — it would let
     * an account skip review it should be under.
     */
    private function recountMembers(?int $siteId, int $chunk, bool $dry): int
    {
        $drift = 0;

        ForumUser::query()
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('id')
            ->chunkById($chunk, function ($members) use (&$drift, $dry): void {
                $ids = $members->pluck('id')->all();

                $totals = ForumPost::query()
                    ->whereIn('forum_user_id', $ids)
                    ->groupBy('forum_user_id')
                    ->selectRaw(
                        'forum_user_id, COUNT(*) AS total, '
                        . "SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved",
                    )
                    ->get()
                    ->keyBy('forum_user_id');

                foreach ($members as $member) {
                    $row = $totals->get($member->id);
                    $correct = [
                        'posts_count'          => (int) ($row->total ?? 0),
                        'approved_posts_count' => (int) ($row->approved ?? 0),
                    ];

                    if (! $this->differs($member, $correct)) {
                        continue;
                    }

                    $drift++;
                    $this->line("  member #{$member->id} approved {$member->approved_posts_count} → {$correct['approved_posts_count']}");

                    if (! $dry) {
                        DB::transaction(fn () => ForumUser::whereKey($member->id)->update($correct));
                    }
                }
            });

        return $drift;
    }

    /**
     * Compare stored against computed.
     *
     * Dates are compared as timestamps: the stored value is a Carbon and the
     * computed one may be a string from an aggregate, and `==` on those is a
     * false positive waiting to happen.
     */
    private function differs(object $model, array $correct): bool
    {
        foreach ($correct as $key => $value) {
            $current = $model->{$key};

            if ($current instanceof \DateTimeInterface || $value instanceof \DateTimeInterface) {
                $a = $current instanceof \DateTimeInterface ? $current->getTimestamp() : null;
                $b = $value instanceof \DateTimeInterface ? $value->getTimestamp() : null;

                if ($a !== $b) {
                    return true;
                }

                continue;
            }

            if ((string) $current !== (string) $value) {
                return true;
            }
        }

        return false;
    }

    /** @return int|null|false  null = every site, false = the option was wrong */
    private function resolveSiteId(): int|null|false
    {
        $option = $this->option('site');

        if ($option === null || $option === '') {
            return null;
        }

        $site = Site::query()
            ->when(ctype_digit((string) $option),
                fn ($q) => $q->whereKey((int) $option),
                fn ($q) => $q->where('slug', $option))
            ->first();

        if ($site === null) {
            $this->error("No site matches \"{$option}\".");

            return false;
        }

        return (int) $site->id;
    }
}
