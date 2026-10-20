<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\DayWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * Which audience the recipient came from.
     *
     * A newsletter subscriber who verified their address, or a member who
     * verified their account registration. Both receive the same template; the
     * label is what lets the history answer a complaint without guessing, and
     * it is a column rather than an inference from `newsletter_id IS NULL`
     * because that null already means "subscriber since deleted".
     */
    public const string SOURCE_NEWSLETTER = 'newsletter';

    public const string SOURCE_REGISTRATION = 'registration';

    /** @var list<string> */
    public const array SOURCES = [self::SOURCE_NEWSLETTER, self::SOURCE_REGISTRATION];

    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'site_id',
        'newsletter_id',
        'source',
        'forum_user_id',
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
            'forum_user_id' => 'integer',
            'is_test'       => 'boolean',
            'sent_at'       => 'datetime',
            'created_at'    => 'datetime',
        ];
    }

    /**
     * Has this address ALREADY been sent the post-verification promotion?
     *
     * THE one definition of "this person already got it", consulted by both
     * send jobs. It lives here rather than in either job because the whole
     * point is that the two audiences agree: a subscriber and a registered
     * member are different rows in different tables, with different claim
     * columns, and neither knows the other exists. This table is the only
     * place their sends meet.
     *
     * Four orderings have to collapse onto one answer, and they do:
     *
     *   subscribe → verify → promotion, then register → verify   → blocked
     *   register  → verify → promotion, then subscribe → verify  → blocked
     *   subscribe twice / register twice                         → blocked by
     *                                                              the row's own
     *                                                              claim column
     *
     * ONLY SUCCESSFUL sends count. A failed attempt means the person never
     * received anything, so it must leave them eligible — otherwise one
     * SendGrid outage would permanently consume somebody's single send. This is
     * why the check is not "is there a row" but "is there a row that worked".
     *
     * CASE-INSENSITIVE for free: `email` is `utf8mb4_unicode_ci`, so MySQL
     * compares `Foo@Bar.com` and `foo@bar.com` as equal without a LOWER() that
     * would make `vps_email_idx` unusable.
     *
     * TEST SENDS COUNT, deliberately. A "Send test" delivers this exact
     * template to a real inbox, so as far as the person reading it is concerned
     * they have received the promotion — and filtering `is_test` out here would
     * both loosen the rule the registration job already applied and let that
     * address be mailed the same thing twice. The flag exists so the admin
     * history can label a row, not so the row can be ignored.
     *
     * Scoped to the SITE, like everything else in this feature: the template is
     * global but it is rendered per site, so two brands are two different
     * emails. This is also the scope the registration job already applied, so
     * no existing behaviour changes by routing it through here.
     */
    public static function alreadySentTo(int $siteId, string $email): bool
    {
        $email = trim($email);

        if ($email === '') {
            return false;
        }

        return self::query()
            ->where('site_id', $siteId)
            ->where('email', $email)
            ->where('status', self::STATUS_SUCCESS)
            ->exists();
    }

    /**
     * How long a sender may hold one address before the lock expires on its own.
     *
     * Generous on purpose: it only has to outlast a single SMTP/API call plus
     * the two history writes. If a worker is killed mid-send the lock is
     * released by this timeout rather than by anybody noticing, and the next
     * sweep re-evaluates the address from scratch.
     */
    public const int SEND_LOCK_SECONDS = 180;

    /**
     * The mutex two send jobs contend for when they target the SAME address.
     *
     * {@see self::alreadySentTo()} answers "has this address been mailed", but
     * it reads a row that is only written AFTER the send — so two jobs that
     * start together both see nothing and both send. That is not hypothetical
     * here: the sweep queues the subscriber job and the member job for one
     * address in the same run, and production runs two queue workers
     * (deploy/supervisor/casino-worker.conf, numprocs=2), so the two can be
     * picked up at the same instant.
     *
     * Holding this lock across the check, the send and the history write makes
     * the three one critical section per address, so the loser arrives after
     * the winner's row exists and its gate sees it.
     *
     * LOWERCASED, unlike the SQL check. There the `utf8mb4_unicode_ci`
     * collation makes the comparison case-insensitive for free; a cache key is
     * a byte-for-byte string compare with no collation to inherit, so
     * `Foo@Bar.com` and `foo@bar.com` would otherwise take two different locks
     * and defeat the point.
     */
    public static function lockKeyFor(int $siteId, string $email): string
    {
        return 'promotion-after-verification:' . $siteId . ':' . mb_strtolower(trim($email));
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

    /**
     * Inclusive from 00:00:00 of the given day, IN THE OPERATOR'S ZONE.
     *
     * `$timezone` is the caller's IANA zone. Absent — an old client, a script,
     * a curl by hand — falls back to UTC, which is exactly what this scope did
     * before, so nothing that already worked changes. See App\Support\DayWindow
     * for why a calendar day cannot be compared against a UTC column without it.
     */
    public function scopeSentFrom(Builder $query, mixed $date, ?string $timezone = null): Builder
    {
        $at = DayWindow::startUtc(self::dayString($date), $timezone);

        return $at === null ? $query : $query->where('sent_at', '>=', $at);
    }

    /**
     * Inclusive to 23:59:59 of the given day, IN THE OPERATOR'S ZONE.
     *
     * Same contract as {@see self::scopeSentFrom()}: absent `$timezone` means
     * UTC, i.e. the previous behaviour.
     */
    public function scopeSentUntil(Builder $query, mixed $date, ?string $timezone = null): Builder
    {
        $at = DayWindow::endUtc(self::dayString($date), $timezone);

        return $at === null ? $query : $query->where('sent_at', '<=', $at);
    }

    /**
     * A `Y-m-d` query-string value as a date, or null when it is not one.
     *
     * Unparseable input is IGNORED rather than rejected: this is a read-only
     * listing, and a stale bookmark should show the unfiltered history instead
     * of a 500.
     */
    private static function dayString(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
