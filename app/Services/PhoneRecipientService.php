<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\NewsletterBasedOnPhone;
use App\Support\Phone\PhoneAudienceFilter;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * THE single definition of "which phone numbers a bulk send goes to".
 *
 * Every consumer — the admin's recipient count, the preview, the CSV export and
 * the send fan-out itself — resolves its audience here, so the four cannot drift
 * apart and the total an admin sees before pressing send is precisely the set
 * that gets messaged. Nothing else may rebuild this query.
 *
 * Reads `newsletters_based_on_phone` and NOTHING else. No join, no subquery and
 * no lookup touches `newsletters`, `clients` or any other table; the audience is
 * a function of this list plus the admin's {@see PhoneAudienceFilter}.
 *
 * Three things compose the audience:
 *   1. opted-out numbers are excluded, always
 *      ({@see NewsletterBasedOnPhone::scopeSubscribed()});
 *   2. the filter's created_at window, when one is selected;
 *   3. the filter's search term, when one is entered.
 * On top of that sits an optional cap on the newest N numbers.
 *
 * Every traversal is keyset-paginated ({@see stream()}): memory stays flat
 * whatever the list size, and unlike OFFSET paging a row inserted mid-traversal
 * can never shift the window and cause a number to be skipped or sent twice.
 */
class PhoneRecipientService
{
    /** Rows fetched per round-trip when streaming. */
    public const int STREAM_CHUNK = 500;

    /**
     * The sendable audience: the filters, plus the unconditional opt-out
     * exclusion. The base every other query in this class builds on.
     *
     * Private, and there is deliberately no public accessor returning the raw
     * builder. Every consumer goes through {@see count()}, {@see sample()},
     * {@see eachChunk()} or {@see exportRows()}, all of which are keyset-paginated
     * — handing out the builder would invite a caller to page it with OFFSET and
     * quietly reintroduce the skipped-recipient bug this class exists to avoid.
     */
    private function audience(PhoneAudienceFilter $filter, ?CarbonInterface $now = null): Builder
    {
        return $this->applyFilters(
            NewsletterBasedOnPhone::query()->subscribed(),
            $filter,
            $now,
        );
    }

    /**
     * Translate a filter into WHERE clauses on an existing query — THE single
     * definition of what the admin's filter selection means in SQL.
     *
     * Public because the admin LISTING needs the same date and search semantics
     * while showing opted-out numbers too (an admin has to be able to see who
     * opted out). Keeping that shared is the point: if the listing interpreted
     * "last month" even slightly differently from the send, the preview count
     * would stop predicting the audience — which is precisely the bug this whole
     * class exists to prevent.
     *
     * The opt-out exclusion deliberately lives in {@see audience()} and NOT here,
     * so no caller can accidentally build a sendable audience that includes
     * numbers which asked to stop.
     *
     * @param  Builder<NewsletterBasedOnPhone>  $query
     * @return Builder<NewsletterBasedOnPhone>
     */
    public function applyFilters(Builder $query, PhoneAudienceFilter $filter, ?CarbonInterface $now = null): Builder
    {
        $query->search($filter->search);

        $range = $filter->resolvedRange($now ?? Carbon::now());

        if ($range === null) {
            return $query;
        }

        [$start, $end] = $range;

        // Either endpoint may be null: "before X" and "after X" are open-ended on
        // one side.
        if ($start !== null) {
            $query->where('created_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('created_at', '<=', $end);
        }

        return $query;
    }

    /**
     * Exactly how many numbers would be messaged right now.
     *
     * In limit mode the answer is the smaller of the cap and the filtered total —
     * computed rather than queried with a LIMIT, because MySQL will not apply a
     * LIMIT to a COUNT and a subquery here would cost a full materialisation for
     * a number that is one min() away.
     */
    public function count(PhoneAudienceFilter $filter, ?CarbonInterface $now = null): int
    {
        $total = $this->audience($filter, $now)->count();

        return $filter->usesLimit() ? min($total, (int) $filter->limit) : $total;
    }

    /**
     * The first $limit recipients, in the order the send processes them.
     *
     * @return Collection<int, NewsletterBasedOnPhone>
     */
    public function sample(PhoneAudienceFilter $filter, int $limit = 25, ?CarbonInterface $now = null): Collection
    {
        $rows = new Collection();

        foreach ($this->stream($filter, min($limit, self::STREAM_CHUNK), $now) as $chunk) {
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
     * Hand every recipient to $callback in chunks of $size. Used by the send
     * fan-out, so the send and the preview share one traversal.
     *
     * @param  callable(Collection<int, NewsletterBasedOnPhone>): void  $callback
     */
    public function eachChunk(
        PhoneAudienceFilter $filter,
        int $size,
        callable $callback,
        ?CarbonInterface $now = null,
    ): void {
        foreach ($this->stream($filter, $size, $now) as $chunk) {
            $callback($chunk);
        }
    }

    /**
     * Rows for the CSV export: [phone, created_at] and nothing else.
     *
     * A generator, so {@see \App\Support\CsvExport} streams straight to the client
     * and the full list is never held in memory.
     *
     * @return Generator<int, array{0: string, 1: string}>
     */
    public function exportRows(PhoneAudienceFilter $filter, ?CarbonInterface $now = null): Generator
    {
        foreach ($this->stream($filter, self::STREAM_CHUNK, $now) as $chunk) {
            foreach ($chunk as $recipient) {
                yield [
                    (string) $recipient->phone,
                    $recipient->created_at?->toDateTimeString() ?? '',
                ];
            }
        }
    }

    /**
     * The one traversal every consumer is built on: keyset pagination over the
     * audience, yielding a chunk at a time.
     *
     * Both directions walk the (created_at, id) key, which is exactly the
     * (opted_out, created_at) index plus InnoDB's implicit primary key — so each
     * page is index-served with no sort, and no page re-reads what an earlier one
     * already returned.
     *
     * @return Generator<int, Collection<int, NewsletterBasedOnPhone>>
     */
    private function stream(PhoneAudienceFilter $filter, int $size, ?CarbonInterface $now = null): Generator
    {
        $now ??= Carbon::now();
        $size = max(1, $size);

        // Newest-N mode walks the key DESCENDING, because "the newest N" is
        // defined from the recent end of the list.
        $descending = $filter->usesLimit();
        $remaining = $descending ? (int) $filter->limit : PHP_INT_MAX;
        $cursor = null;

        while ($remaining > 0) {
            $page = $this->audience($filter, $now)
                ->limit((int) min($size, $remaining));

            if ($descending) {
                $page->orderByDesc('created_at')->orderByDesc('id');
            } else {
                $page->orderBy('created_at')->orderBy('id');
            }

            if ($cursor !== null) {
                [$lastCreatedAt, $lastId] = $cursor;
                $comparator = $descending ? '<' : '>';

                $page->where(function (Builder $q) use ($lastCreatedAt, $lastId, $comparator): void {
                    $q->where('created_at', $comparator, $lastCreatedAt)
                        // The tiebreaker is not optional: an import writes
                        // thousands of rows with an identical created_at, and
                        // without it a page boundary landing inside a tie would
                        // repeat some numbers and skip others.
                        ->orWhere(function (Builder $tie) use ($lastCreatedAt, $lastId, $comparator): void {
                            $tie->where('created_at', '=', $lastCreatedAt)
                                ->where('id', $comparator, $lastId);
                        });
                });
            }

            $rows = $page->get(['id', 'phone', 'created_at']);

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
