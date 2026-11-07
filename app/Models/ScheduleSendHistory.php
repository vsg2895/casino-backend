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
 * One address's outcome for one scheduled send — the Schedule tab of Email
 * History, and the record the schedule cooldown reads.
 *
 * Written for EVERY audience (newsletters, both Custom Receivers lists,
 * warmup). The newsletter audience also keeps writing `promotion_email_histories`,
 * whose 24-hour dedup it shares with the after-verification promotion; this
 * table is what knows the schedule, the list and the email config.
 *
 * The cooldown is per SITE and TEMPLATE, not per schedule: two schedules of one
 * site send the same promotion email, so an address one of them reached is
 * resting for the other too.
 */
class ScheduleSendHistory extends Model
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_SKIPPED = 'skipped';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SKIPPED];

    /** Diagnostic head of a transport error; full API responses can be huge. */
    private const int MAX_ERROR_LENGTH = 500;

    /** Fallback for the cooldown tolerance, matching promotion history. */
    private const int DEFAULT_JITTER_MINUTES = 180;

    public $timestamps = false;

    protected $fillable = [
        'email_schedule_id', 'schedule_name', 'site_id', 'audience', 'recipient_id',
        'email', 'template', 'provider', 'credential_id', 'credential_label',
        'from_address', 'status', 'error', 'sent_at', 'sent_date',
    ];

    protected function casts(): array
    {
        return [
            'recipient_id'  => 'integer',
            'credential_id' => 'integer',
            'sent_at'       => 'datetime',
            'sent_date'     => 'date',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(EmailSchedule::class, 'email_schedule_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Start of the cooldown: a `sent` row after this moment rests the address.
     *
     * Same tolerance as the promotion dedup ({@see PromotionEmailHistory::dedupCutoff()}),
     * for the same reason: a daily run takes time, so yesterday's 03:40 delivery
     * must not still block today's 03:00 run of a one-day cooldown. Clamped to
     * stay inside one day, so no configuration can switch the cooldown off.
     */
    public static function cooldownCutoff(int $days, ?CarbonInterface $now = null): Carbon
    {
        $jitter = max(0, min((int) config('promotions.dedup_jitter_minutes', self::DEFAULT_JITTER_MINUTES), 1439));

        return ($now ? Carbon::instance($now) : Carbon::now())
            ->copy()
            ->subDays(max(1, $days))
            ->addMinutes($jitter);
    }

    /**
     * THE definition of "this address is resting": it received this site's
     * template successfully after the cutoff. Applied to $query as a subquery
     * body, correlated on $emailColumn — or, with null, for a caller that
     * constrains the address itself.
     *
     * Shared by the audience selection (count, preview, export, send) and the
     * send-time re-check, so what an admin previews is what is mailed.
     */
    public static function scopeResting(
        QueryBuilder $query,
        int $siteId,
        string $template,
        int $days,
        ?string $emailColumn = null,
        ?CarbonInterface $now = null,
    ): void {
        $cutoff = self::cooldownCutoff($days, $now);

        $query->from((new self())->getTable())
            ->where('site_id', $siteId)
            ->where('template', $template)
            ->where('status', self::STATUS_SENT)
            ->where('sent_at', '>', $cutoff);

        if ($emailColumn !== null) {
            $query->whereColumn('schedule_send_histories.email', $emailColumn);
        }
    }

    /**
     * Which of $emails are resting — one query, for the send-time re-check.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    public static function restingAmong(int $siteId, string $template, int $days, array $emails, ?CarbonInterface $now = null): array
    {
        if ($emails === []) {
            return [];
        }

        return static::query()
            ->tap(fn (Builder $q) => self::scopeResting($q->getQuery(), $siteId, $template, $days, null, $now))
            ->whereIn('email', $emails)
            ->distinct()
            ->pluck('email')
            ->all();
    }

    /**
     * Write a batch's outcomes in ONE statement.
     *
     * An upsert against the daily unique key, so a retried job or a second
     * worker can never record a second `sent` for the same address, site,
     * template and day. On conflict only `error` is refreshed — `sent_at`
     * anchors the cooldown and must not drift.
     *
     * @param  list<array<string, mixed>>  $rows  Columns of this table, minus sent_at / sent_date.
     */
    public static function record(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = Carbon::now();

        $rows = array_map(static fn (array $row): array => [
            ...$row,
            'error'     => ($row['status'] ?? null) === self::STATUS_FAILED ? self::truncate($row['error'] ?? null) : null,
            'sent_at'   => $now,
            'sent_date' => $now->toDateString(),
        ], $rows);

        static::query()->upsert(
            $rows,
            ['site_id', 'template', 'email', 'sent_date', 'status'],
            ['error'],
        );
    }

    private static function truncate(mixed $error): ?string
    {
        $error = trim((string) $error);

        return $error === '' ? null : mb_strimwidth($error, 0, self::MAX_ERROR_LENGTH, '…');
    }
}
