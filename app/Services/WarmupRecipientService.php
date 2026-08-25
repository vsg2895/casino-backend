<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\WarmupEmail;
use Generator;
use Illuminate\Database\Eloquent\Builder;
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
 * SELECTION HAS TWO INDEPENDENT PARTS, and both are expressed as model scopes so
 * the count, the preview and the send can never drift apart:
 *
 *  1. ORDER — most recently added first ({@see WarmupEmail::scopeRecent()}).
 *     A freshly imported batch is what the operator wants warmed next.
 *  2. FILTER — skip anything successfully contacted inside the cooldown window
 *     ({@see WarmupEmail::scopeNotContactedWithin()}).
 *
 * Part 2 is what makes part 1 safe. Ordering by recency alone would hand every
 * run to the same head of the list; the cooldown retires each address for N days
 * after it is reached, so successive runs walk further down the list on their own.
 *
 * A cooldown of null (the "send to every address" option) disables the filter and
 * every address is contacted.
 */
class WarmupRecipientService
{
    /** Rows fetched per round-trip when streaming. */
    public const int STREAM_CHUNK = 500;

    /**
     * Addresses this run would actually contact.
     *
     * Not `min(total, limit)`: with a cooldown in force the eligible set is
     * usually smaller than the list, and quoting the list total would promise the
     * admin a send that cannot happen.
     */
    public function count(?int $limit = null, ?int $cooldownDays = null): int
    {
        $eligible = $this->eligible($cooldownDays);

        return $limit === null ? $eligible : min($eligible, $limit);
    }

    /** How many addresses pass the cooldown filter, ignoring any recipient cap. */
    public function eligible(?int $cooldownDays = null): int
    {
        return $this->selection($cooldownDays)->count();
    }

    /** Total addresses on the list, i.e. the cap the admin's input is validated against. */
    public function available(): int
    {
        return WarmupEmail::query()->count();
    }

    /**
     * Hand every selected address to $callback in chunks of $size.
     *
     * @param  int|null  $limit         Null means every eligible address — the
     *                                  "send to everyone" option.
     * @param  int|null  $cooldownDays  Null disables the cooldown filter.
     * @param  callable(Collection<int, WarmupEmail>): void  $callback
     */
    public function eachChunk(?int $limit, ?int $cooldownDays, int $size, callable $callback): void
    {
        foreach ($this->stream($limit, $cooldownDays, $size) as $chunk) {
            $callback($chunk);
        }
    }

    /**
     * The eligible set, unordered and unpaged — the one place the filter lives.
     *
     * @return Builder<WarmupEmail>
     */
    private function selection(?int $cooldownDays): Builder
    {
        return WarmupEmail::query()->notContactedWithin($cooldownDays);
    }

    /**
     * Keyset traversal over (created_at DESC, id DESC).
     *
     * Walks exactly `warmup_emails_recency_index`, so each page is index-served
     * with no filesort, holds one chunk in memory, and can never repeat or skip an
     * address when many rows share a `created_at` — which is the norm, since one
     * import stamps its entire file with a single timestamp.
     *
     * OFFSET paging would be wrong here as well as slow: the send stamps
     * `last_sent_at` as it goes, so rows drop out of the filtered set mid-traversal
     * and every later offset would silently skip addresses.
     *
     * `created_at` is never NULL — both the manual create and the batched import
     * write it explicitly — so the cursor needs no NULL branch.
     *
     * @return Generator<int, Collection<int, WarmupEmail>>
     */
    private function stream(?int $limit, ?int $cooldownDays, int $size): Generator
    {
        $size = max(1, $size);
        $remaining = $limit ?? PHP_INT_MAX;
        $cursor = null;

        while ($remaining > 0) {
            $page = $this->selection($cooldownDays)
                ->recent()
                ->limit((int) min($size, $remaining));

            if ($cursor !== null) {
                [$lastCreatedAt, $lastId] = $cursor;

                $page->where(function (Builder $q) use ($lastCreatedAt, $lastId): void {
                    $q->where('created_at', '<', $lastCreatedAt)
                        ->orWhere(function (Builder $tie) use ($lastCreatedAt, $lastId): void {
                            $tie->where('created_at', '=', $lastCreatedAt)->where('id', '<', $lastId);
                        });
                });
            }

            $rows = $page->get(['id', 'email', 'created_at', 'last_sent_at']);

            if ($rows->isEmpty()) {
                return;
            }

            $remaining -= $rows->count();
            $last = $rows->last();
            $cursor = [$last->created_at, (int) $last->id];

            yield $rows;
        }
    }
}
