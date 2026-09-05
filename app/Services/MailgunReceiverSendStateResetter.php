<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MailgunReceiver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Clears the send counters on Mailgun receivers — the "Last sent" and "Sent"
 * columns in the admin Receivers table.
 *
 * The single expression of the reset. The artisan command and the admin button
 * both call this, so the two can never drift into resetting different things —
 * the same discipline the batch scopes on {@see MailgunReceiver} keep for
 * selection.
 *
 * Resets `last_sent_at` to NULL and `sent_count` to 0, and optionally
 * `last_error`. Nothing else is touched: `unsubscribed_at` and the shared
 * suppression list record a person's own decision, and a counter reset must
 * never read as permission to mail someone again who opted out.
 *
 * WHAT THIS CHANGES BEYOND THE COLUMNS: `last_sent_at` is what
 * {@see MailgunReceiver::scopeNotContactedWithin()} reads, so clearing it makes
 * every receiver qualify for the next campaign regardless of the cooldown window
 * configured on the credential. That is the point of the operation and also its
 * whole risk — every caller must say so before running it.
 *
 * The send history in `mailgun_receiver_sends` is deliberately left intact. The
 * counters here are a denormalised mirror kept for cheap batch selection; the
 * history is the record of what was actually delivered, and destroying it to
 * tidy a column would throw away the only audit trail of who was mailed when.
 * The two disagreeing after a reset is expected.
 *
 * Soft-deleted receivers are included. A restored row carrying counters from
 * before the reset would sit in exactly the state this exists to make
 * impossible.
 */
class MailgunReceiverSendStateResetter
{
    /** Rows updated per round-trip. */
    private const int CHUNK = 1000;

    /**
     * How many receivers still carry send state.
     *
     * Narrowed to rows that actually need the write, so the number means
     * "would change", not "exists" — which is what the confirmation prompt and
     * the admin dialog both report.
     */
    public function pendingCount(bool $clearErrors = false): int
    {
        return $this->query($clearErrors)->count();
    }

    /**
     * Reset in id-ordered chunks. Returns the number of rows changed.
     *
     * Each pass re-runs the same query and updated rows fall out of it, so the
     * set shrinks until empty. Nothing larger than one chunk of ids is held in
     * memory, and no single transaction spans the whole table.
     *
     * `$onProgress` receives the rows changed by each pass, so a CLI caller can
     * drive a progress bar without this class knowing what a console is.
     *
     * @param  (callable(int): void)|null  $onProgress
     */
    public function reset(bool $clearErrors = false, ?callable $onProgress = null): int
    {
        $values = ['last_sent_at' => null, 'sent_count' => 0];

        if ($clearErrors) {
            $values['last_error'] = null;
        }

        $reset = 0;

        while (true) {
            $ids = $this->query($clearErrors)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // toBase(): an Eloquent update() would silently add updated_at and
            // overwrite the real edit time on every row in the table.
            $updated = MailgunReceiver::withTrashed()->whereKey($ids)->toBase()->update($values);

            // A pass that matches rows but writes none would spin forever.
            if ($updated === 0) {
                break;
            }

            $reset += $updated;

            if ($onProgress !== null) {
                $onProgress($updated);
            }
        }

        return $reset;
    }

    /** Receivers still carrying send state. Also what makes the chunk loop terminate. */
    private function query(bool $clearErrors): Builder
    {
        return MailgunReceiver::withTrashed()
            ->where(static function (Builder $q) use ($clearErrors): void {
                $q->whereNotNull('last_sent_at')->orWhere('sent_count', '>', 0);

                if ($clearErrors) {
                    $q->orWhereNotNull('last_error');
                }
            });
    }
}
