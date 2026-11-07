<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Mail\ReceiverSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One address on a Custom Receivers list: {@see MailgunReceiver} or
 * {@see SendgridReceiver}.
 *
 * The two lists are separate tables with separate claims and histories, and
 * they never share a row. What they share is everything ELSE, and it lives
 * here: the normalised email, the unsubscribe token, and the scopes that ARE
 * the batch predicate. The selector, the send loop and the import resolve the
 * list from the credential and then call these, so a rule changed here changes
 * both lists together. Two copies of these scopes is how one list would end up
 * mailing people the other one excludes.
 *
 * A subclass names its own tables through the three statics at the bottom.
 * Nothing else about a list is configurable, on purpose.
 */
abstract class CampaignReceiver extends Model
{
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
        // Whose list this address came from. Distinct from `source` above,
        // which is "import" or "manual" and says how the ROW was created, not
        // where the person came from. A send can be narrowed to chosen labels.
        'source_label',
        'consent_recorded_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'           => 'boolean',
            // Whose list this address came from. A closed set — see ReceiverSource.
            'source_label'        => ReceiverSource::class,
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

    /** Absolute, token-addressed, and unguessable — see newToken(). */
    public function unsubscribeUrl(): string
    {
        return rtrim((string) config('app.url'), '/')
            . '/api/v1/' . static::unsubscribePath() . '/'
            . $this->unsubscribe_token;
    }

    /**
     * The list an operator names on the command line: `mailgun` or `sendgrid`.
     * Null for anything else, so a typo is reported rather than defaulted.
     *
     * @return class-string<CampaignReceiver>|null
     */
    public static function forList(string $list): ?string
    {
        return match (strtolower(trim($list))) {
            'mailgun'  => MailgunReceiver::class,
            'sendgrid' => SendgridReceiver::class,
            default    => null,
        };
    }

    // ── What a list names for itself ─────────────────────────────────────────

    /**
     * The column that points at this row from the claims and history tables —
     * `mailgun_receiver_id` or `sendgrid_receiver_id`.
     */
    abstract public static function receiverForeignKey(): string;

    /**
     * The one-email-per-day table for this list.
     *
     * Per list rather than shared: the claim is keyed by receiver id, and ids in
     * two tables are not the same person.
     */
    abstract public static function dailyClaimsTable(): string;

    /** Public route prefix of this list's unsubscribe links, under /api/v1/. */
    abstract public static function unsubscribePath(): string;

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
     * ONE suppression list for both Custom Receivers lists, keyed by email: an
     * address that unsubscribed from, or hard-bounced on, one list is not mailed
     * from the other. Stopping is the person's decision about the sender, not
     * about which provider carried the message.
     *
     * A NOT EXISTS correlated subquery rather than whereNotIn: the suppression
     * list can grow to the same order as the receiver list, and pulling it into
     * PHP to build an IN clause is exactly the "load a large dataset into
     * memory" the brief forbids. This stays entirely in the database and is
     * served by the unique index on mailgun_suppressions.email.
     */
    public function scopeNotSuppressed(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereNotExists(static function ($sub) use ($table): void {
            $sub->selectRaw('1')
                ->from('mailgun_suppressions')
                ->whereColumn('mailgun_suppressions.email', $table . '.email');
        });
    }

    /**
     * Narrow to chosen sources.
     *
     * An empty list means BOTH — the audience every credential had before this
     * column existed, so an untouched credential keeps mailing everyone. An
     * unrecognised value is DROPPED rather than matched: a stale admin bundle
     * sending a removed option must not quietly mail nobody.
     *
     * The column is NOT NULL with a database default, so there is no third
     * "unlabelled" state to account for — every address is one of the two.
     *
     * @param  list<ReceiverSource|string>|null  $sources
     */
    public function scopeFromSources(Builder $query, ?array $sources): Builder
    {
        $values = collect($sources ?? [])
            ->map(static fn (ReceiverSource|string|null $source): ?ReceiverSource => ReceiverSource::parse($source))
            ->filter()
            ->map(static fn (ReceiverSource $source): string => $source->value)
            ->unique()
            ->values()
            ->all();

        // Both chosen is the same audience as none chosen; skipping the clause
        // keeps the plan on the cheaper index.
        if ($values === [] || count($values) === count(ReceiverSource::cases())) {
            return $query;
        }

        return $query->whereIn('source_label', $values);
    }

    /**
     * Excludes anyone who already has today's claim.
     *
     * The claims table is unique on (receiver, day) across every credential of
     * the list, so a claimed address CANNOT be mailed again today — the send
     * loop skips it. Leaving those rows in the selection meant they still
     * consumed slots in the batch: with a batch of 100 whose first 100 were
     * claimed, a run mailed nobody and never reached the thousands of untouched
     * addresses behind them.
     *
     * Filtering here makes the batch fill with addresses that can actually be
     * mailed, and makes the counts in the modal mean "sendable now". The atomic
     * claim in ReceiverCampaignSender stays exactly as it was — that is the race
     * guard between two workers, and this does not replace it.
     *
     * NOT EXISTS on the unique index, so it stays an index lookup per row.
     */
    public function scopeNotClaimedToday(Builder $query): Builder
    {
        $today = Carbon::now()->toDateString();
        $table = $query->getModel()->getTable();
        $claims = static::dailyClaimsTable();
        $foreignKey = static::receiverForeignKey();

        return $query->whereNotExists(static function ($sub) use ($today, $table, $claims, $foreignKey): void {
            $sub->selectRaw('1')
                ->from($claims)
                ->whereColumn($claims . '.' . $foreignKey, $table . '.id')
                ->where($claims . '.claim_on', $today);
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
