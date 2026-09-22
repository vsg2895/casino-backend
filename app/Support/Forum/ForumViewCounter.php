<?php

declare(strict_types=1);

namespace App\Support\Forum;

use App\Models\ForumArticle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Buffered view counting.
 *
 * ── The problem ─────────────────────────────────────────────────────────────
 *
 * `UPDATE forum_articles SET views_count = views_count + 1 WHERE id = ?` on
 * every pageview takes a row lock on the article for the duration of the
 * transaction. On a thread that is actually popular — the only kind whose view
 * count anybody cares about — every concurrent reader queues behind every other
 * one on the same row. The counter becomes a global lock on the hot article, and
 * the busier the page gets the worse it serves.
 *
 * ── What this does instead ──────────────────────────────────────────────────
 *
 * 1. `SET forum:viewed:{article}:{visitor} NX EX 86400` — a per-visitor,
 *    per-article dedupe that also costs nothing to check. Only a NEW key counts,
 *    so a reader refreshing twenty times adds one view, not twenty.
 * 2. `HINCRBY forum:views {article} 1` — an in-memory counter, no MySQL lock.
 * 3. `forum:flush-views` drains the hash on a schedule and writes ONE update per
 *    article that actually moved.
 *
 * A thousand views on one article become one row lock a minute instead of a
 * thousand lock acquisitions.
 *
 * ── When Redis is gone ──────────────────────────────────────────────────────
 *
 * Falls back to a direct increment guarded by the visitor's session, once per
 * article per session. It undercounts during an outage, and that is the right
 * trade: the alternative is reintroducing per-pageview lock contention at
 * exactly the moment the system is already degraded. A view count is the least
 * important number on the page.
 */
final class ForumViewCounter
{
    private const HASH = 'forum:views';

    private const SEEN_PREFIX = 'forum:viewed:';

    private const SEEN_TTL = 86_400;

    /** Guard the direct path with this session key when Redis is unavailable. */
    private const SESSION_KEY = 'forum.viewed';

    /**
     * Record one view.
     *
     * `$visitorKey` is a hash of address + user agent for a guest, or the member
     * id for a signed-in reader. Never a raw address: this key lives in Redis
     * for a day and there is no reason for it to be personally identifying.
     */
    public static function record(int $articleId, string $visitorKey): void
    {
        try {
            $seenKey = self::SEEN_PREFIX . $articleId . ':' . $visitorKey;

            // `set(... 'NX')` returns falsy when the key already existed, which
            // is exactly the dedupe. One round trip, no read-then-write race.
            if (! Redis::set($seenKey, 1, 'EX', self::SEEN_TTL, 'NX')) {
                return;
            }

            Redis::hincrby(self::HASH, (string) $articleId, 1);
        } catch (Throwable $e) {
            Log::warning('Forum view counting fell back to the direct path', [
                'article' => $articleId,
                'error'   => $e->getMessage(),
            ]);

            self::recordDirect($articleId);
        }
    }

    /**
     * The degraded path: one increment per article per session.
     *
     * Session-guarded rather than unguarded, so even without Redis a refresh
     * loop cannot hammer one row.
     */
    private static function recordDirect(int $articleId): void
    {
        try {
            if (! request()->hasSession()) {
                return;
            }

            $seen = (array) session(self::SESSION_KEY, []);

            if (in_array($articleId, $seen, true)) {
                return;
            }

            $seen[] = $articleId;
            session([self::SESSION_KEY => $seen]);

            ForumArticle::query()->whereKey($articleId)->update([
                'views_count' => DB::raw('views_count + 1'),
            ]);
        } catch (Throwable $e) {
            // A view counter must never be able to fail a page render.
            Log::warning('Forum view counting failed entirely', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Drain the buffer into MySQL.
     *
     * Reads and clears atomically-enough: the hash is renamed to a scratch key
     * first, so views arriving during the flush accumulate in a fresh hash
     * rather than being counted and then deleted. Without that rename, every
     * view that landed between the read and the delete would be lost.
     *
     * @return int  articles updated
     */
    public static function flush(): int
    {
        $scratch = self::HASH . ':flushing:' . uniqid('', true);

        try {
            if (! Redis::exists(self::HASH)) {
                return 0;
            }

            Redis::rename(self::HASH, $scratch);
            $counts = Redis::hgetall($scratch);
        } catch (Throwable $e) {
            Log::warning('Could not drain the forum view buffer', ['error' => $e->getMessage()]);

            return 0;
        }

        $updated = 0;

        foreach ($counts as $articleId => $delta) {
            $delta = (int) $delta;

            if ($delta <= 0) {
                continue;
            }

            // One statement per article that moved — not per view.
            $updated += ForumArticle::query()
                ->whereKey((int) $articleId)
                ->update(['views_count' => DB::raw('views_count + ' . $delta)]);
        }

        try {
            Redis::del($scratch);
        } catch (Throwable) {
            // The scratch key expires on its own if this fails; losing it is
            // not worth failing the flush over.
        }

        return $updated;
    }

    /** A non-identifying key for the current visitor. */
    public static function visitorKey(?int $memberId, string $ip, string $userAgent): string
    {
        if ($memberId !== null) {
            return 'm' . $memberId;
        }

        return 'g' . substr(hash('xxh128', $ip . '|' . $userAgent), 0, 16);
    }
}
