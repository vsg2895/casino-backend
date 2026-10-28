<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\DayWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One warmup delivery attempt — which address was mailed, from which site, with
 * which template, and when.
 *
 * The per-recipient counterpart of {@see WarmupSend}: that row says a run
 * happened, this one says what each address actually received. A batch never
 * aborts on one bad address, so this table is the only place a partial failure
 * is visible afterwards.
 *
 * `email` is stored as a plain string alongside the nullable `warmup_email_id`:
 * the history records what was sent and must outlive the address being removed
 * from the list. Same choice, for the same reason, as {@see PhoneSmsHistory}
 * storing a number and {@see PromotionEmailHistory} storing an address.
 */
class WarmupSendRecipient extends Model
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SENT, self::STATUS_FAILED];

    protected $fillable = [
        'warmup_send_id',
        'warmup_email_id',
        'site_id',
        'email',
        'template',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'warmup_send_id'  => 'integer',
            'warmup_email_id' => 'integer',
            'site_id'         => 'integer',
            'sent_at'         => 'datetime',
        ];
    }

    public function warmupSend(): BelongsTo
    {
        return $this->belongsTo(WarmupSend::class);
    }

    public function warmupEmail(): BelongsTo
    {
        return $this->belongsTo(WarmupEmail::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Case-insensitive prefix search on the address.
     *
     * Matches {@see WarmupEmail::scopeSearch()} exactly, so the same input
     * behaves the same way in the list and in the history.
     *
     * Every filter scope below takes `mixed` rather than a narrow type on
     * purpose: these are fed straight from the query string, and `?site_id[]=1`
     * hands a PHP array to a `?string` parameter, which is a TypeError — a 500
     * from a URL anyone can type. Non-scalars are ignored instead.
     */
    public function scopeSearch(Builder $query, mixed $term): Builder
    {
        $term = is_scalar($term) ? trim((string) $term) : '';

        if ($term === '') {
            return $query;
        }

        // Escape LIKE wildcards so user input cannot turn into a %..% scan.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where('email', 'like', $escaped . '%');
    }

    /**
     * Narrow to one run — what the "Last send" banner's failed list reads, so
     * the rows it shows are exactly the ones its `failed_count` counted.
     */
    public function scopeForRun(Builder $query, mixed $sendId): Builder
    {
        if (! is_scalar($sendId) || trim((string) $sendId) === '') {
            return $query;
        }

        return $query->where('warmup_send_id', (int) $sendId);
    }

    /** Narrow to one site, one template or one outcome — the history filters. */
    public function scopeForSite(Builder $query, mixed $siteId): Builder
    {
        if (! is_scalar($siteId) || trim((string) $siteId) === '') {
            return $query;
        }

        return $query->where('site_id', (int) $siteId);
    }

    public function scopeForTemplate(Builder $query, mixed $template): Builder
    {
        if (! is_string($template) || $template === '') {
            return $query;
        }

        return $query->where('template', $template);
    }

    public function scopeWithStatus(Builder $query, mixed $status): Builder
    {
        return in_array($status, self::STATUSES, true) ? $query->where('status', $status) : $query;
    }

    /**
     * Lower bound of the `sent_at` history filter, inclusive from midnight
     * IN THE OPERATOR'S ZONE.
     *
     * `$timezone` is the caller's IANA zone; absent falls back to UTC, which is
     * exactly what this scope did before. See App\Support\DayWindow.
     */
    public function scopeSentFrom(Builder $query, mixed $from, ?string $timezone = null): Builder
    {
        $at = DayWindow::startUtc(self::dayString($from), $timezone);

        return $at === null ? $query : $query->where('sent_at', '>=', $at);
    }

    /**
     * Upper bound of the `sent_at` history filter, inclusive of the whole day
     * IN THE OPERATOR'S ZONE.
     *
     * The old comment here named the very bug this now fixes: "both bounds are
     * UTC days … for an admin outside UTC a row near midnight can display on
     * the adjacent day". It does not any more — the bound is resolved in the
     * caller's zone and converted, so the row the table shows on the 20th is
     * the row the filter for the 20th returns.
     *
     * The half-open `< next midnight` form is gone with it. It existed to stop
     * a bare `'2026-09-01'` being read as that day's 00:00:00 and dropping the
     * whole day; DayWindow hands over a real instant at 23:59:59, so `<=` is
     * exact. `sent_at` is `timestamp(0)`, checked — with fractional seconds the
     * half-open form would be required again.
     *
     * Still no `whereDate()`: wrapping the column in a function makes
     * `warmup_recipients_sent_index` unusable.
     */
    public function scopeSentUntil(Builder $query, mixed $to, ?string $timezone = null): Builder
    {
        $at = DayWindow::endUtc(self::dayString($to), $timezone);

        return $at === null ? $query : $query->where('sent_at', '<=', $at);
    }

    /**
     * A filter value parsed to a date, or null when it is absent or unparseable.
     *
     * Null rather than an exception: these are read-only list filters, and a
     * malformed `?sent_from=whatever` should return the unfiltered list, not a
     * 500. The send path validates properly.
     */
    private static function dayString(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * Newest first, with `id` as the tiebreaker.
     *
     * Not cosmetic: one batch writes up to 100 rows sharing a single `sent_at`,
     * and MySQL gives no stable order among ties — without the tiebreaker, paging
     * repeats some rows and skips others. Served by `warmup_recipients_sent_index`.
     */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderByDesc('sent_at')->orderByDesc('id');
    }
}
