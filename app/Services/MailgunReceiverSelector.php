<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ReceiverCampaignCredential;
use App\Models\MailgunReceiver;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Resolves WHICH receivers a credential sends to on its next run.
 *
 * THE single definition of that question. The live count in the settings modal,
 * the "Preview batch" listing and the sending job all enter through this class,
 * so what an admin previews is exactly what the job mails. Duplicating the
 * predicate anywhere else is how those three silently drift apart — the same
 * reasoning {@see WarmupRecipientService} documents.
 *
 * Memory behaviour is the point of the streaming methods. At 100k receivers a
 * `->get()` would hydrate 100k models; {@see stream()} holds one chunk at a time
 * using KEYSET pagination on (created_at, id) rather than OFFSET, so page 500
 * costs the same as page 1 and no row can be repeated or skipped when rows are
 * inserted mid-run.
 */
final class MailgunReceiverSelector
{
    /** Hard ceiling on a hydrated preview, whatever batch size is configured. */
    private const int PREVIEW_MAX = 500;

    /**
     * The predicate, and nothing else.
     *
     * Every public method below builds on this, so a change here changes the
     * count, the preview and the send together.
     */
    public function selection(ReceiverCampaignCredential $credential): Builder
    {
        return MailgunReceiver::query()
            ->sendable()
            ->notSuppressed()
            ->notContactedWithin($credential->campaignCooldownDays());
    }

    /**
     * How many receivers match the rule, ignoring the batch cap.
     *
     * A COUNT in the database — never a hydrated collection — so the modal's
     * live figure costs one indexed query regardless of list size.
     */
    public function eligible(ReceiverCampaignCredential $credential): int
    {
        return $this->selection($credential)->count();
    }

    /**
     * How many the NEXT run would actually take: eligible, capped by batch_size.
     *
     * Not `min(total, batch)` off a stale total — the eligible set shrinks as the
     * cooldown retires addresses, so it is recomputed.
     */
    public function batchCount(ReceiverCampaignCredential $credential): int
    {
        return min($this->eligible($credential), $credential->campaignBatchSize());
    }

    /**
     * The exact rows the next run would mail, for the preview listing.
     *
     * Capped at $limit ?? batch_size, so previewing a 100k list still returns one
     * page. Selects only the columns the preview renders.
     *
     * @return Collection<int, MailgunReceiver>
     */
    public function preview(ReceiverCampaignCredential $credential, ?int $limit = null): Collection
    {
        // The default is CAPPED, not just defaulted. `preview()` hydrates models
        // — unlike stream(), which pages — so an uncapped default would let a
        // credential configured for 100 000 pull the whole run into memory to
        // render a listing nobody scrolls. Both current callers pass 100; this
        // ceiling is what stops the next one from being the exception.
        $take = min($limit ?? $credential->campaignBatchSize(), self::PREVIEW_MAX);

        return $this->selection($credential)
            ->inSelectionOrder($credential->campaignSelectionOrder())
            ->limit($take)
            ->get(['id', 'email', 'name', 'created_at', 'last_sent_at', 'sent_count']);
    }

    /**
     * Stream the batch in chunks, holding one chunk in memory at a time.
     *
     * Keyset pagination, not OFFSET: with an offset, the database still walks
     * every skipped row, so deep pages get progressively slower, and any row
     * inserted or retired mid-run shifts the window and causes duplicates or
     * gaps. The cursor is (created_at, id) — the same pair the index is built on
     * and the same technique the warmup sender uses.
     *
     * @param  int  $chunkSize  Rows per yielded chunk.
     * @return Generator<int, Collection<int, MailgunReceiver>>
     */
    public function stream(ReceiverCampaignCredential $credential, int $chunkSize = 500): Generator
    {
        $remaining = $credential->campaignBatchSize();
        $chunkSize = max(1, $chunkSize);
        $order = $credential->campaignSelectionOrder();
        $descending = $order !== MailgunReceiver::ORDER_OLDEST;
        $cursor = null;

        while ($remaining > 0) {
            $page = $this->selection($credential)
                ->inSelectionOrder($order)
                ->limit((int) min($chunkSize, $remaining));

            if ($cursor !== null) {
                [$lastCreatedAt, $lastId] = $cursor;

                $page->where(static function (Builder $q) use ($lastCreatedAt, $lastId, $descending): void {
                    $comparison = $descending ? '<' : '>';

                    $q->where('created_at', $comparison, $lastCreatedAt)
                        ->orWhere(static function (Builder $tie) use ($lastCreatedAt, $lastId, $comparison): void {
                            $tie->where('created_at', '=', $lastCreatedAt)
                                ->where('id', $comparison, $lastId);
                        });
                });
            }

            /** @var Collection<int, MailgunReceiver> $rows */
            $rows = $page->get(['id', 'email', 'name', 'created_at', 'last_sent_at', 'sent_count']);

            if ($rows->isEmpty()) {
                return;
            }

            $remaining -= $rows->count();
            $last = $rows->last();
            $cursor = [$last->created_at, (int) $last->id];

            yield $rows;

            // Drop the chunk's models before the next query so peak memory is one
            // chunk, not the whole batch.
            unset($rows, $last);
        }
    }
}
