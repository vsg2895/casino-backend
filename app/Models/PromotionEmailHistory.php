<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Long-term promotion attempt history (see the create + add-status migrations).
 * One row per processing outcome per email/day — success, failed, or skipped —
 * written in bulk (one INSERT per campaign batch) and read by the admin
 * history view.
 *
 * The UNIQUE (site_id, email, sent_date, status) also makes it the dedup
 * guard for the send flow: at most one SUCCESS row can exist per email/day —
 * {@see sentWithinDayAmong()} + the upsert in {@see recordAttempts()}.
 */
class PromotionEmailHistory extends Model
{
    public const string STATUS_SUCCESS = 'success'; // delivered to the transport
    public const string STATUS_FAILED = 'failed';   // send threw; logged, batch continued
    public const string STATUS_SKIPPED = 'skipped'; // already delivered within the last 24h

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SUCCESS, self::STATUS_FAILED, self::STATUS_SKIPPED];

    /**
     * Tolerance subtracted from the 24h dedup window so a daily schedule that
     * fires at the same minute every day is never skip-flapped by a few seconds
     * of scheduler/queue jitter (yesterday 03:00:05 must not block today 03:00:02).
     */
    private const int DEDUP_JITTER_MINUTES = 5;

    /**
     * Cap on the stored failure message. Transport errors can carry very long
     * payloads (full API responses); this table is partitioned and retained
     * forever, so keep only the diagnostic head of the message.
     */
    private const int MAX_ERROR_LENGTH = 500;

    public $timestamps = false;

    protected $table = 'promotion_email_histories';

    protected $fillable = ['site_id', 'email', 'sent_date', 'status', 'error', 'created_at'];

    protected function casts(): array
    {
        return [
            'sent_date'  => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Of the given candidate addresses, which SUCCESSFULLY received this site's
     * promotion within the last 24 hours (minus the jitter tolerance) — one
     * query, used to skip them before sending. Only `success` rows count:
     * a failed or skipped attempt never blocks a later delivery.
     *
     * The window spans at most two calendar days, so constraining `sent_date`
     * keeps the (site_id, sent_date) index + monthly partition pruning in play.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    public static function sentWithinDayAmong(int $siteId, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $cutoff = Carbon::now()->subDay()->addMinutes(self::DEDUP_JITTER_MINUTES);

        return static::query()
            ->where('site_id', $siteId)
            ->where('status', self::STATUS_SUCCESS)
            ->whereIn('sent_date', [
                $cutoff->toDateString(),
                Carbon::today()->toDateString(),
            ])
            ->where('created_at', '>', $cutoff)
            ->whereIn('email', $emails)
            ->pluck('email')
            ->all();
    }

    /**
     * Bulk-insert one history row per processed address in a SINGLE INSERT —
     * not one query per recipient. Called once per campaign batch with every
     * attempted address and its outcome (success / failed / skipped), plus the
     * error message for any that failed.
     *
     * The upsert against UNIQUE (site_id, email, sent_date, status) makes it
     * idempotent: a job retry or a concurrent worker can never write a
     * duplicate outcome for the same email/day — in particular, never a second
     * `success` record. On conflict ONLY `error` is refreshed, so a same-day
     * repeat failure reports the LATEST reason while `created_at` stays put —
     * that timestamp anchors the 24h dedup window and must never drift.
     * (Refreshing a success/skipped row is a no-op: their error is always null.)
     *
     * @param  array<string, string>       $statusByEmail  email => STATUS_* value
     * @param  array<string, string|null>  $errorByEmail   email => failure message
     */
    public static function recordAttempts(int $siteId, array $statusByEmail, array $errorByEmail = []): void
    {
        if ($statusByEmail === []) {
            return;
        }

        $now = Carbon::now();
        $sentDate = $now->toDateString();

        $rows = [];
        foreach ($statusByEmail as $email => $status) {
            $rows[] = [
                'site_id'    => $siteId,
                'email'      => $email,
                'sent_date'  => $sentDate,
                'status'     => $status,
                // Only failures carry an error; a delivered or skipped attempt
                // has nothing to report.
                'error'      => $status === self::STATUS_FAILED
                    ? self::truncateError($errorByEmail[$email] ?? null)
                    : null,
                'created_at' => $now,
            ];
        }

        static::query()->upsert(
            $rows,
            ['site_id', 'email', 'sent_date', 'status'],
            ['error'],
        );
    }

    /** Normalise a failure message for storage (trimmed, capped, null if empty). */
    private static function truncateError(?string $error): ?string
    {
        $error = trim((string) $error);

        if ($error === '') {
            return null;
        }

        return mb_strimwidth($error, 0, self::MAX_ERROR_LENGTH, '…');
    }

    /**
     * Convenience wrapper: record the given addresses as successful deliveries.
     *
     * @param  list<string>  $emails
     */
    public static function recordMany(int $siteId, array $emails): void
    {
        static::recordAttempts(
            $siteId,
            array_fill_keys($emails, self::STATUS_SUCCESS),
        );
    }
}
