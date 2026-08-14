<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WarmupEmail;
use Generator;
use Illuminate\Support\Collection;

/**
 * THE single definition of "which warmup addresses this run contacts".
 *
 * Mirrors the SHAPE of {@see ScheduleRecipientService} — a keyset traversal that
 * streams the audience in chunks so memory stays flat — but is a separate class
 * on purpose. The promotion resolver is coupled to sites, opt-outs and the 24h
 * delivery-history dedup, none of which apply to a warmup list; sharing one class
 * would mean adding warmup branches to code that runs 50k-recipient campaigns.
 * The promotion path is not modified by this feature.
 *
 * Selection order is LEAST RECENTLY CONTACTED first
 * ({@see WarmupEmail::scopeRotation()}). Random selection over-picks some
 * addresses and starves others; ordering by `created_at` keeps hitting the same
 * head of the list. Rotation warms the whole list evenly, which is the entire
 * point of a warmup list.
 */
class WarmupRecipientService
{
    /** Rows fetched per round-trip when streaming. */
    public const int STREAM_CHUNK = 500;

    /** How many addresses this run would contact. */
    public function count(?int $limit = null): int
    {
        $total = WarmupEmail::query()->count();

        return $limit === null ? $total : min($total, $limit);
    }

    /** Total addresses on the list, i.e. the cap the admin's input is validated against. */
    public function available(): int
    {
        return WarmupEmail::query()->count();
    }

    /**
     * Hand every selected address to $callback in chunks of $size.
     *
     * @param  int|null  $limit  Null means the WHOLE list — the "send to everyone"
     *                           option. A number takes that many, rotation-first.
     * @param  callable(Collection<int, WarmupEmail>): void  $callback
     */
    public function eachChunk(?int $limit, int $size, callable $callback): void
    {
        foreach ($this->stream($limit, $size) as $chunk) {
            $callback($chunk);
        }
    }

    /**
     * Keyset traversal over the rotation order.
     *
     * Walks (last_sent_at, id) — exactly the `warmup_emails_rotation_index` — so
     * each page is index-served with no sort, holds one chunk in memory, and can
     * never repeat or skip an address when many rows share the same
     * `last_sent_at` (which is the norm: an import leaves them all NULL, and a run
     * stamps a whole batch with the same timestamp).
     *
     * @return Generator<int, Collection<int, WarmupEmail>>
     */
    private function stream(?int $limit, int $size): Generator
    {
        $size = max(1, $size);
        $remaining = $limit ?? PHP_INT_MAX;
        $cursor = null;

        while ($remaining > 0) {
            $page = WarmupEmail::query()
                ->rotation()
                ->limit((int) min($size, $remaining));

            if ($cursor !== null) {
                [$lastSentAt, $lastId] = $cursor;

                $page->where(function ($q) use ($lastSentAt, $lastId): void {
                    if ($lastSentAt === null) {
                        // Still inside the NULL block: only `id` can advance us,
                        // and every non-NULL row sorts after the whole block.
                        $q->whereNull('last_sent_at')->where('id', '>', $lastId)
                            ->orWhereNotNull('last_sent_at');

                        return;
                    }

                    $q->where('last_sent_at', '>', $lastSentAt)
                        ->orWhere(function ($tie) use ($lastSentAt, $lastId): void {
                            $tie->where('last_sent_at', '=', $lastSentAt)->where('id', '>', $lastId);
                        });
                });
            }

            $rows = $page->get(['id', 'email', 'last_sent_at']);

            if ($rows->isEmpty()) {
                return;
            }

            $remaining -= $rows->count();
            $last = $rows->last();
            $cursor = [$last->last_sent_at, (int) $last->id];

            yield $rows;
        }
    }
}
