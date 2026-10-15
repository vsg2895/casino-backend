<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt to send the post-verification promotion to one subscriber.
 *
 * The stream's own log, shaped after {@see WarmupSendRecipient} because the
 * admin screen it feeds is the same shape as Warmup History: one row per
 * attempt, filtered by site, status, address and date.
 *
 * Immutable — hence `UPDATED_AT = null`. An attempt happened or it did not.
 *
 * `email` is kept beside `newsletter_id` deliberately: the subscriber can be
 * deleted later, and the record of what was sent has to stay readable when they
 * are. Every filter scope below takes `mixed` rather than a narrow type for the
 * same reason its sibling does — they are fed straight from the query string,
 * and `?site_id[]=1` would otherwise be a TypeError from a URL anyone can type.
 */
class VerificationPromotionSend extends Model
{
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SUCCESS, self::STATUS_FAILED];

    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'site_id',
        'newsletter_id',
        'email',
        'status',
        'error',
        // Set by this section's "Send test" button — see the migration that
        // added the column for why a test is logged and flagged rather than
        // either hidden or indistinguishable.
        'is_test',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id'       => 'integer',
            'newsletter_id' => 'integer',
            'is_test'       => 'boolean',
            'sent_at'       => 'datetime',
            'created_at'    => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function newsletter(): BelongsTo
    {
        return $this->belongsTo(Newsletter::class);
    }

    /** Newest attempt first, with `id` as the tiebreak for one-second bursts. */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderByDesc('sent_at')->orderByDesc('id');
    }

    /**
     * Case-insensitive PREFIX search on the address.
     *
     * Prefix rather than contains, matching the newsletter and warmup searches:
     * it is the shape an index can serve, and `vps_email_idx` serves it.
     */
    public function scopeSearch(Builder $query, mixed $term): Builder
    {
        $term = is_scalar($term) ? trim((string) $term) : '';

        if ($term === '') {
            return $query;
        }

        // Escape LIKE wildcards so user input cannot turn into a %…% scan.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where('email', 'like', $escaped . '%');
    }

    public function scopeForSite(Builder $query, mixed $siteId): Builder
    {
        if (! is_scalar($siteId) || trim((string) $siteId) === '') {
            return $query;
        }

        return $query->where('site_id', (int) $siteId);
    }

    public function scopeWithStatus(Builder $query, mixed $status): Builder
    {
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            return $query;
        }

        return $query->where('status', $status);
    }

    /** Inclusive from 00:00:00 of the given day. */
    public function scopeSentFrom(Builder $query, mixed $date): Builder
    {
        $day = self::day($date);

        return $day === null ? $query : $query->where('sent_at', '>=', $day->startOfDay());
    }

    /** Inclusive to 23:59:59 of the given day. */
    public function scopeSentUntil(Builder $query, mixed $date): Builder
    {
        $day = self::day($date);

        return $day === null ? $query : $query->where('sent_at', '<=', $day->endOfDay());
    }

    /**
     * A `Y-m-d` query-string value as a date, or null when it is not one.
     *
     * Unparseable input is IGNORED rather than rejected: this is a read-only
     * listing, and a stale bookmark should show the unfiltered history instead
     * of a 500.
     */
    private static function day(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', trim((string) $value));
        } catch (\Throwable) {
            return null;
        }
    }
}
