<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One address reachable through a stored Mailgun credential.
 *
 * Sibling of {@see Newsletter} in shape only — this pool is global rather than
 * per-site, and nothing here reads or writes the newsletter tables.
 *
 * The scopes below are the ONLY place the batch predicate is expressed. The
 * preview count, the preview listing and the sending job all compose the same
 * three, which is what stops the admin previewing one set and the job mailing
 * another. Same discipline as {@see WarmupRecipientService}.
 */
class MailgunReceiver extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const string SOURCE_IMPORT = 'import';
    public const string SOURCE_MANUAL = 'manual';

    /** @var list<string> */
    public const array SOURCES = [self::SOURCE_IMPORT, self::SOURCE_MANUAL];

    public const string ORDER_NEWEST = 'newest';
    public const string ORDER_OLDEST = 'oldest';

    /** @var list<string> */
    public const array ORDERS = [self::ORDER_NEWEST, self::ORDER_OLDEST];

    protected $fillable = [
        'email',
        'name',
        'source',
        'consent_recorded_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'           => 'boolean',
            'consent_recorded_at' => 'datetime',
            'unsubscribed_at'     => 'datetime',
            'last_sent_at'        => 'datetime',
            'sent_count'          => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Generated here rather than in the controller so EVERY creation path —
        // manual form, spreadsheet import, factory, tinker — gets a token. A row
        // without one could be mailed but never unsubscribed from.
        static::creating(function (self $receiver): void {
            if ((string) $receiver->unsubscribe_token === '') {
                $receiver->unsubscribe_token = self::newToken();
            }
        });
    }

    /**
     * Normalise on write, so the unique index actually means "one person".
     *
     * Without this, "A@X.com " and "a@x.com" are two rows, both mailable, and a
     * suppression on one would not cover the other.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value): string => Str::lower(trim((string) $value)),
        );
    }

    /** 64 hex chars — matches the newsletter token width and column length. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ── Batch selection ──────────────────────────────────────────────────────
    // Composed by MailgunReceiverSelector and by nothing else.

    /**
     * Sendable: never unsubscribed.
     *
     * `is_active` is deliberately NOT part of this. Every receiver on the list is
     * meant to be mailed — the column survives only because dropping a column
     * from a deployed table buys nothing — so gating sends on it would let a row
     * sit on the list looking present while silently receiving nothing.
     *
     * `unsubscribed_at` stays, and is not negotiable: it records a person's own
     * decision, which no setting may override. Suppression is layered on top by
     * {@see scopeNotSuppressed()}.
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query->whereNull('unsubscribed_at');
    }

    /**
     * Exclude anything on the shared suppression list.
     *
     * A NOT EXISTS correlated subquery rather than whereNotIn: the suppression
     * list can grow to the same order as the receiver list, and pulling it into
     * PHP to build an IN clause is exactly the "load a large dataset into
     * memory" the brief forbids. This stays entirely in the database and is
     * served by the unique index on mailgun_suppressions.email.
     */
    public function scopeNotSuppressed(Builder $query): Builder
    {
        return $query->whereNotExists(static function ($sub): void {
            $sub->selectRaw('1')
                ->from('mailgun_suppressions')
                ->whereColumn('mailgun_suppressions.email', 'mailgun_receivers.email');
        });
    }

    /**
     * Skip anyone contacted within the cooldown window.
     *
     * Null or < 1 disables the filter. The NULL branch on `last_sent_at` is
     * load-bearing: a never-contacted receiver must always qualify, and MySQL
     * drops NULLs from a plain `<=` comparison. Copied deliberately from
     * {@see WarmupEmail::scopeNotContactedWithin()}, which documents the same trap.
     */
    /**
     * Excludes anyone who already has today's claim.
     *
     * `receiver_daily_claims` is unique on (receiver, day) across every
     * credential and both channels, so a claimed address CANNOT be mailed again
     * today — the send loop skips it. Leaving those rows in the selection meant
     * they still consumed slots in the batch: with a batch of 100 whose first
     * 100 were claimed, a run mailed nobody and never reached the thousands of
     * untouched addresses behind them.
     *
     * Filtering here makes the batch fill with addresses that can actually be
     * mailed, and makes the counts in the modal mean "sendable now". The atomic
     * claim in ReceiverCampaignSender stays exactly as it was — that is the race
     * guard between two workers, and this does not replace it.
     *
     * NOT EXISTS on the unique index, so it stays an index lookup per row.
     *
     * @param  Builder<MailgunReceiver>  $query
     * @return Builder<MailgunReceiver>
     */
    public function scopeNotClaimedToday(Builder $query): Builder
    {
        $today = Carbon::now()->toDateString();

        return $query->whereNotExists(static function ($sub) use ($today): void {
            $sub->selectRaw('1')
                ->from('receiver_daily_claims')
                ->whereColumn('receiver_daily_claims.mailgun_receiver_id', 'mailgun_receivers.id')
                ->where('receiver_daily_claims.claim_on', $today);
        });
    }

    public function scopeNotContactedWithin(Builder $query, ?int $days): Builder
    {
        if ($days === null || $days < 1) {
            return $query;
        }

        $cutoff = Carbon::now()->subDays($days);

        return $query->where(static function (Builder $q) use ($cutoff): void {
            $q->whereNull('last_sent_at')->orWhere('last_sent_at', '<=', $cutoff);
        });
    }

    /** Selection order, backed by the (created_at, id) keyset index. */
    public function scopeInSelectionOrder(Builder $query, string $order): Builder
    {
        $direction = $order === self::ORDER_OLDEST ? 'asc' : 'desc';

        return $query->orderBy('created_at', $direction)->orderBy('id', $direction);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where(static function (Builder $q) use ($escaped): void {
            $q->where('email', 'like', $escaped . '%')
                ->orWhere('name', 'like', '%' . $escaped . '%');
        });
    }
}
