<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
     * Default tolerance subtracted from the 24h dedup window.
     *
     * Two things need it. The small one: a daily schedule firing at the same
     * minute must not skip-flap on a few seconds of scheduler jitter (yesterday
     * 03:00:05 must not block today 03:00:02). The large one: a campaign takes
     * as long as it takes to send. A 50k list over one transport runs for hours,
     * so by the time the next day's run starts, most of yesterday's deliveries
     * are still inside a strict 24h window and the whole campaign silently
     * skips itself. The tolerance must therefore comfortably exceed the longest
     * expected campaign duration — hence 3 hours rather than 5 minutes.
     *
     * The live value is the literal in config/promotions.php; this constant is
     * only the fallback if that file is ever missing.
     */
    private const int DEFAULT_DEDUP_JITTER_MINUTES = 180;

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
    public static function sentWithinDayAmong(int $siteId, array $emails, ?CarbonInterface $now = null): array
    {
        if ($emails === []) {
            return [];
        }

        return static::query()
            ->tap(fn (Builder $q) => self::scopeDeliveredWithinDay($q->getQuery(), $siteId, null, $now))
            ->whereIn('email', $emails)
            ->pluck('email')
            ->all();
    }

    /**
     * Start of the 24-hour dedup window: anything delivered after this moment
     * blocks a re-send. The jitter tolerance keeps a daily schedule that fires
     * at the same minute from skip-flapping itself.
     */
    public static function dedupCutoff(?CarbonInterface $now = null): Carbon
    {
        return ($now ? Carbon::instance($now) : Carbon::now())
            ->copy()->subDay()->addMinutes(self::dedupJitterMinutes());
    }

    /**
     * The configured tolerance, clamped to stay inside the 24h window.
     *
     * At 1440+ the cutoff would move past "now" and nothing could ever be
     * deduped — every campaign would re-mail everyone. The clamp makes that
     * unreachable by configuration alone.
     */
    private static function dedupJitterMinutes(): int
    {
        $minutes = (int) config('promotions.dedup_jitter_minutes', self::DEFAULT_DEDUP_JITTER_MINUTES);

        return max(0, min($minutes, 1439));
    }

    /**
     * THE definition of "this address already received this site's promotion
     * within the last 24 hours" — applied to $query.
     *
     * Shared by the send-time skip ({@see sentWithinDayAmong()}) and by the
     * audience resolver that powers the recipient count / preview / export
     * ({@see \App\Services\ScheduleRecipientService}), so the number an admin
     * previews can never disagree with what the campaign actually delivers.
     *
     * Only `success` rows count: a failed or skipped attempt must not block a
     * later delivery. Constraining `sent_date` to the (at most two) calendar
     * days the window spans keeps the (site_id, sent_date) index and the
     * monthly partition pruning in play.
     *
     * @param  string|null  $emailColumn  Column to correlate against when used
     *                                    as a subquery (e.g. 'newsletters.email');
     *                                    null compares nothing and expects the
     *                                    caller to constrain the address.
     */
    public static function scopeDeliveredWithinDay(
        QueryBuilder $query,
        int $siteId,
        ?string $emailColumn = null,
        ?CarbonInterface $now = null,
    ): void {
        $cutoff = self::dedupCutoff($now);

        $query->from((new self())->getTable())
            ->where('site_id', $siteId)
            ->where('status', self::STATUS_SUCCESS)
            ->whereIn('sent_date', [
                $cutoff->toDateString(),
                $cutoff->copy()->addDay()->toDateString(),
            ])
            ->where('created_at', '>', $cutoff);

        if ($emailColumn !== null) {
            $query->whereColumn('promotion_email_histories.email', $emailColumn);
        }
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
