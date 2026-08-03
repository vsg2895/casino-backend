<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Unsubscribe;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * THE single definition of "who receives this schedule's campaign".
 *
 * Every consumer — the scheduled send itself, the admin recipient preview, the
 * count, and the CSV export — goes through this class, so the four can never
 * drift apart. Nothing else may rebuild the audience query.
 *
 * Three filters compose the audience:
 *   1. the schedule's site;
 *   2. minus anyone who opted out of the promotion stream;
 *   3. minus anyone who ALREADY received this site's promotion in the last
 *      24 hours (see {@see PromotionEmailHistory::scopeDeliveredWithinDay()}),
 *      because the send would skip them — so the previewed count is what is
 *      actually delivered, and a second run of the same schedule resolves to
 *      zero recipients rather than re-mailing.
 *
 * On top of that sits one of two mutually exclusive audience modes:
 *   - sign-up date window: subscribers created inside dateRange()
 *   - newest N: the `limit` most recent sign-ups, by created_at desc
 *
 * Every traversal is keyset-paginated ({@see stream()}): memory stays flat
 * regardless of list size, and unlike OFFSET paging a concurrent sign-up can
 * never shift the window and cause a recipient to be skipped.
 */
class ScheduleRecipientService
{
    /** Rows fetched per round-trip when streaming. */
    public const int STREAM_CHUNK = 500;

    /**
     * The audience query — filtered, ordered and (in newest-N mode) already
     * capped. Never executed here; callers add their own projection.
     */
    public function query(EmailSchedule $schedule, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();
        $siteId = $schedule->site_id;

        if ($schedule->usesLimit()) {
            // Take the newest N FIRST, then drop those already delivered to.
            // Filtering after the slice is essential: filtering before it would
            // let a second run reach deeper into the list and mail a DIFFERENT
            // N subscribers, when it should mail nobody.
            return Newsletter::query()
                ->fromSub($this->newestSlice($schedule), 'newsletters')
                ->whereNotExists($this->alreadyDelivered($siteId, $now))
                ->orderByDesc('created_at')
                ->orderByDesc('id');
        }

        [$start, $end] = $schedule->dateRange($now);

        return $this->audience($siteId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotExists($this->alreadyDelivered($siteId, $now));
    }

    /**
     * The site's promotion-eligible subscribers: everyone who has not opted out
     * of the promotion stream. The base every other query here builds on — no
     * caller may reconstruct it.
     */
    private function audience(int $siteId): Builder
    {
        return Newsletter::query()
            ->where('site_id', $siteId)
            // Promotion opt-outs, in one correlated pass against the
            // (site_id, email, type) unique key.
            ->whereNotExists(function (QueryBuilder $sub) use ($siteId): void {
                $sub->from('unsubscribes')
                    ->whereColumn('unsubscribes.email', 'newsletters.email')
                    ->where('unsubscribes.site_id', $siteId)
                    ->where('unsubscribes.type', Unsubscribe::TYPE_PROMOTION);
            });
    }

    /**
     * The "newest N sign-ups" slice, before the already-delivered filter — the
     * definition of the audience in limit mode.
     */
    private function newestSlice(EmailSchedule $schedule): Builder
    {
        return $this->audience($schedule->site_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit((int) $schedule->limit)
            // `deleted_at` must travel into the derived table: Newsletter is
            // soft-deleting, so the outer query's global scope references
            // newsletters.deleted_at (the inner has already filtered it).
            ->select(['id', 'email', 'created_at', 'deleted_at']);
    }

    /**
     * Exactly how many subscribers would be mailed right now.
     *
     * In newest-N mode the cap lives inside the subquery, so counting the outer
     * query yields the capped, deduplicated total directly.
     */
    public function count(EmailSchedule $schedule, ?CarbonInterface $now = null): int
    {
        return $this->query($schedule, $now)->reorder()->count();
    }

    /**
     * The first $limit recipients, in the order the campaign processes them —
     * email + created_at only, for the preview table.
     *
     * @return Collection<int, Newsletter>
     */
    public function sample(EmailSchedule $schedule, int $limit = 25, ?CarbonInterface $now = null): Collection
    {
        $rows = new Collection();

        foreach ($this->stream($schedule, min($limit, self::STREAM_CHUNK), $now) as $chunk) {
            foreach ($chunk as $recipient) {
                $rows->push($recipient);

                if ($rows->count() >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * Hand every recipient to $callback in chunks of $size. Used by the campaign
     * fan-out, so the send and the preview/export share one traversal.
     *
     * @param  callable(Collection<int, Newsletter>): void  $callback
     */
    public function eachChunk(EmailSchedule $schedule, int $size, callable $callback, ?CarbonInterface $now = null): void
    {
        foreach ($this->stream($schedule, $size, $now) as $chunk) {
            $callback($chunk);
        }
    }

    /**
     * Rows for the CSV export: [email, created_at] and nothing else.
     *
     * A generator, so {@see \App\Support\CsvExport} streams straight to the
     * client and the full list is never held in memory.
     *
     * @return Generator<int, array{0: string, 1: string}>
     */
    public function exportRows(EmailSchedule $schedule, ?CarbonInterface $now = null): Generator
    {
        foreach ($this->stream($schedule, self::STREAM_CHUNK, $now) as $chunk) {
            foreach ($chunk as $recipient) {
                yield [
                    $recipient->email,
                    $recipient->created_at?->toDateTimeString() ?? '',
                ];
            }
        }
    }

    /**
     * The "already got this promotion in the last 24h" exclusion, as a
     * correlated subquery closure. Delegates to the history model so the send
     * and the preview share one definition of the window.
     */
    private function alreadyDelivered(int $siteId, ?CarbonInterface $now): callable
    {
        return static function (QueryBuilder $sub) use ($siteId, $now): void {
            PromotionEmailHistory::scopeDeliveredWithinDay($sub, $siteId, 'newsletters.email', $now);
        };
    }

    /**
     * The one traversal every consumer is built on: keyset pagination over the
     * audience, yielding one chunk at a time.
     *
     * Both modes walk the (created_at, id) key — ascending through the window,
     * descending through the newest-N slice — so each page is served by the
     * newsletters (site_id, created_at) index, holds a single chunk in memory,
     * and is immune to rows being inserted mid-traversal.
     *
     * @return Generator<int, Collection<int, Newsletter>>
     */
    private function stream(EmailSchedule $schedule, int $size, ?CarbonInterface $now = null): Generator
    {
        $now ??= now();

        if ($schedule->usesLimit()) {
            yield from $this->streamNewest($schedule, $size, $now);

            return;
        }

        yield from $this->streamWindow($schedule, $size, $now);
    }

    /**
     * Date-window mode: keyset over (created_at, id) ascending.
     *
     * The key deliberately matches the newsletters (site_id, created_at) index —
     * which implicitly carries the primary key — so both the range filter and
     * the ordering are index-served and no page needs a sort. Walking plain `id`
     * instead (the obvious chunkById shape) cannot use that index for ordering,
     * and degrades into a primary-key scan once a site has enough subscribers.
     *
     * @return Generator<int, Collection<int, Newsletter>>
     */
    private function streamWindow(EmailSchedule $schedule, int $size, CarbonInterface $now): Generator
    {
        $base = $this->query($schedule, $now)->select(['id', 'email', 'created_at']);
        $cursor = null;

        while (true) {
            $page = (clone $base)->reorder()->orderBy('created_at')->orderBy('id')->limit($size);

            if ($cursor !== null) {
                [$lastCreatedAt, $lastId] = $cursor;
                $page->where(function (Builder $q) use ($lastCreatedAt, $lastId): void {
                    $q->where('created_at', '>', $lastCreatedAt)
                        ->orWhere(function (Builder $tie) use ($lastCreatedAt, $lastId): void {
                            $tie->where('created_at', '=', $lastCreatedAt)->where('id', '>', $lastId);
                        });
                });
            }

            $rows = $page->get();

            if ($rows->isEmpty()) {
                return;
            }

            yield $rows;

            $last = $rows->last();
            $cursor = [$last->created_at, (int) $last->id];
        }
    }

    /**
     * Newest-N mode: page the SLICE, then filter each page.
     *
     * The obvious implementation — paging {@see query()}, whose subquery is
     * `LIMIT N` — re-runs that subquery for every page, so a 50k campaign sorts
     * and materialises 50k rows once per page instead of once in total. Here the
     * cursor walks the slice itself, and the already-delivered filter is applied
     * per page against the history's unique key.
     *
     * Semantics are unchanged: rows count towards N whether or not they survive
     * the filter, so a second run still resolves to nobody rather than reaching
     * deeper into the list.
     *
     * @return Generator<int, Collection<int, Newsletter>>
     */
    private function streamNewest(EmailSchedule $schedule, int $size, CarbonInterface $now): Generator
    {
        $siteId = $schedule->site_id;
        $remaining = (int) $schedule->limit;
        $cursor = null;

        while ($remaining > 0) {
            $page = $this->audience($siteId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(min($size, $remaining));

            if ($cursor !== null) {
                [$lastCreatedAt, $lastId] = $cursor;
                $page->where(function (Builder $q) use ($lastCreatedAt, $lastId): void {
                    $q->where('created_at', '<', $lastCreatedAt)
                        ->orWhere(function (Builder $tie) use ($lastCreatedAt, $lastId): void {
                            $tie->where('created_at', '=', $lastCreatedAt)->where('id', '<', $lastId);
                        });
                });
            }

            $slice = $page->get(['id', 'email', 'created_at']);

            if ($slice->isEmpty()) {
                return;
            }

            // Advance before filtering: the cap counts sliced rows, not mailed ones.
            $remaining -= $slice->count();
            $last = $slice->last();
            $cursor = [$last->created_at, (int) $last->id];

            // Same 24h definition as the count/preview — one indexed lookup for
            // the page rather than a correlated subquery per row.
            $delivered = array_flip(
                PromotionEmailHistory::sentWithinDayAmong($siteId, $slice->pluck('email')->all(), $now),
            );

            $rows = $slice
                ->reject(static fn (Newsletter $recipient): bool => isset($delivered[(string) $recipient->email]))
                ->values();

            unset($slice, $delivered);

            if ($rows->isNotEmpty()) {
                yield $rows;
            }
        }
    }
}
