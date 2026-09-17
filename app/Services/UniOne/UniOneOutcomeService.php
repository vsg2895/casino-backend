<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Models\UniOne\UniOneReceiver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a per-address outcome to a receiver.
 *
 * ONE implementation, used by both the synchronous `failed_emails` path and the
 * asynchronous webhook path. That is the point: two mappings would eventually
 * disagree about what "blocked" means, and the list would drift depending on
 * which signal arrived first.
 */
class UniOneOutcomeService
{
    /**
     * Apply a UniOne `failed_emails` reason.
     *
     * `temporary_unavailable` is the case that is NOT a status change: the
     * address stays `active` and gets a `retry_after`, because a soft failure
     * says something about the moment, not the mailbox. Flipping the status
     * would need a second job to flip it back and would misreport why the
     * address went quiet.
     *
     * `duplicate` changes nothing at all — it describes the REQUEST, not the
     * address, and is prevented by de-duplicating before the request is built.
     */
    public function applyFailure(UniOneReceiver $receiver, string $reason): void
    {
        $now = now();

        if ($reason === 'duplicate') {
            return;
        }

        if ($reason === 'temporary_unavailable') {
            $receiver->forceFill([
                'last_status' => $reason,
                'retry_after' => $now->copy()->addDays(UniOneReceiver::TEMPORARY_FAILURE_DAYS),
            ])->save();

            return;
        }

        $status = UniOneReceiver::FAILURE_STATUS_MAP[$reason] ?? null;

        if ($status === null) {
            // An unrecognised reason is recorded, never guessed at. UniOne could
            // add a value; inventing a status for it would be worse than leaving
            // the address alone and letting the log show it.
            $receiver->forceFill(['last_status' => $reason])->save();

            return;
        }

        $updates = ['status' => $status, 'last_status' => $reason, 'retry_after' => null];

        $receiver->forceFill($updates)->save();

        // Counters as atomic SQL, so two concurrent signals for the same address
        // cannot lose an increment.
        if ($status === UniOneReceiver::STATUS_BOUNCED) {
            UniOneReceiver::query()->whereKey($receiver->id)->update(['bounce_count' => DB::raw('bounce_count + 1')]);
        }

        if ($status === UniOneReceiver::STATUS_COMPLAINED) {
            UniOneReceiver::query()->whereKey($receiver->id)->update(['complaint_count' => DB::raw('complaint_count + 1')]);
        }
    }

    /**
     * Apply a webhook `email_status` event.
     *
     * Routed to the same mapping: `hard_bounced` lands where `invalid` does,
     * `spam` where `complained` does, `soft_bounced` where
     * `temporary_unavailable` does.
     */
    public function applyEventStatus(UniOneReceiver $receiver, string $status): bool
    {
        return match ($status) {
            'hard_bounced' => $this->applyAndReturn($receiver, 'permanent_unavailable'),
            'spam'         => $this->applyAndReturn($receiver, 'complained'),
            'unsubscribed' => $this->applyAndReturn($receiver, 'unsubscribed'),
            'soft_bounced' => $this->applyAndReturn($receiver, 'temporary_unavailable'),
            // delivered / opened / clicked / sent record progress without
            // changing eligibility.
            default => $this->recordOnly($receiver, $status),
        };
    }

    private function applyAndReturn(UniOneReceiver $receiver, string $reason): bool
    {
        $this->applyFailure($receiver, $reason);

        return true;
    }

    private function recordOnly(UniOneReceiver $receiver, string $status): bool
    {
        $receiver->forceFill(['last_status' => $status])->save();

        return false;
    }

    /** Mark a batch as successfully handed over, inside the caller's transaction. */
    public function markSent(array $receiverIds, Carbon $at): void
    {
        if ($receiverIds === []) {
            return;
        }

        UniOneReceiver::query()
            ->whereIn('id', $receiverIds)
            ->update([
                'last_sent_at' => $at,
                'last_status'  => 'accepted',
                'send_count'   => DB::raw('send_count + 1'),
                'updated_at'   => $at,
            ]);
    }
}
