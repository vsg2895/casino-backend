<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Models\UniOne\UniOneReceiver;
use App\Models\UniOne\UniOneSend;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who gets this run, and in what order.
 *
 * Rotation is `last_sent_at ASC` with nulls first — never-contacted addresses
 * lead, then the least recently contacted. MySQL sorts NULLs first ascending, so
 * the order falls out of `unione_receivers_batch_idx` with no filesort.
 *
 * Every selection goes through `scopeSendable()`, which is where the consent
 * rule lives. There is no path in this class that reads receivers without it.
 */
class UniOneAudienceService
{
    /** @return Builder<UniOneReceiver> */
    public function eligibleQuery(?int $cooldownHours): Builder
    {
        return UniOneReceiver::query()
            ->sendable()
            ->outsideCooldown($cooldownHours);
    }

    /**
     * How many addresses this run could reach.
     *
     * An exact COUNT, which is what the brief's preview asks for. Measured at
     * ~70 ms against 100,000 rows with 95,000 eligible — acceptable for a
     * preview an operator triggers by hand, and linear in the eligible set. If
     * the list ever reaches millions this becomes the thing to cap, not the
     * batch selection, which stays flat.
     */
    public function eligibleCount(?int $cooldownHours): int
    {
        return $this->eligibleQuery($cooldownHours)->count();
    }

    /**
     * The addresses for this run, in rotation order.
     *
     * Materialised once rather than streamed: the send stamps `last_sent_at` as
     * it goes, so rows leave the eligible set mid-traversal. Re-querying per
     * chunk would silently skip addresses — the same trap Warmup documents for
     * OFFSET paging, arriving by a different route.
     *
     * A run is bounded by `requested`, so the memory cost is bounded too.
     *
     * @return Collection<int, UniOneReceiver>
     */
    public function select(int $requested, ?int $cooldownHours): Collection
    {
        $rows = $this->eligibleQuery($cooldownHours)
            ->rotation()
            ->limit(max(1, $requested))
            ->get(['id', 'email', 'name', 'last_sent_at']);

        return $this->deduplicate($rows);
    }

    /**
     * Drop repeated addresses before the request is built.
     *
     * UniOne answers a repeated address with `duplicate` in `failed_emails`,
     * which wastes a slot in a 500-cap request and pollutes the log with a
     * "failure" that says nothing about the mailbox. The unique index makes this
     * near-impossible via the admin, but an import that bypassed it, or a
     * case-difference, would still get through — so the send de-duplicates
     * case-insensitively rather than trusting the table.
     *
     * @param  Collection<int, UniOneReceiver>  $rows
     * @return Collection<int, UniOneReceiver>
     */
    public function deduplicate(Collection $rows): Collection
    {
        return $rows->unique(static fn (UniOneReceiver $r): string => mb_strtolower(trim((string) $r->email)))->values();
    }

    /** How many 500-address requests a run of this size needs. */
    public function chunkCount(int $recipients): int
    {
        return (int) ceil(max(0, $recipients) / UniOneSend::CHUNK_SIZE);
    }
}
