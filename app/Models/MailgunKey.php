<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ReceiverCampaignCredential;
use App\Support\Mail\ReceiverSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * A stored Mailgun credential that can authenticate scheduled promotion sends.
 *
 * Sibling of {@see SendgridKey}. The difference is that Mailgun authenticates a
 * (domain, key) pair rather than a bare key, and US/EU accounts live on
 * different API hosts — hence `domain` and `region`.
 *
 * The `api_key` is cast `encrypted`, so it is written to the DB encrypted and
 * decrypted transparently when building the transport. It must NEVER be
 * returned raw by any API Resource — see {@see maskedKey()}.
 *
 * `from_address` / `from_name` record the sender identity registered with
 * Mailgun for this domain. They are STORED AND EDITABLE ONLY — no send path
 * reads them. Every send takes its sender from the site template's own
 * `from_email`, exactly as before this column pair existed.
 */
class MailgunKey extends Model implements ReceiverCampaignCredential
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const string REGION_US = 'us';
    public const string REGION_EU = 'eu';

    /** @var list<string> */
    public const array REGIONS = [self::REGION_US, self::REGION_EU];

    /**
     * The columns the listing resource actually renders.
     *
     * `message_html` is LONGTEXT (the receiver-campaign template) and
     * `message_template` / `receiver_sources` are JSON; none of the three
     * reaches {@see \App\Http\Resources\MailgunKeyResource}, and the editor
     * loads the template through the dedicated receiver-template-source
     * endpoint. This listing is unpaginated, so a `SELECT *` pulled one full
     * campaign body per credential every time the screen opened — and threw
     * every byte away.
     *
     * `api_key` IS needed here: maskedKey() and canAuthenticate() both read it.
     *
     * An explicit list, not a schema diff, so the cost of a column is visible
     * where the column is declared. ReceiverCredentialColumnTrimTest asserts
     * this renders JSON identical to `SELECT *`, so a field added to the
     * resource cannot silently come back null.
     *
     * @var list<string>
     */
    public const array LIST_COLUMNS = [
        'id',
        'name',
        'domain',
        'api_key',
        'region',
        'from_address',
        'from_name',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'name',
        'domain',
        'api_key',
        'region',
        'from_address',
        'from_name',
        'status',
        // Receiver targeting. Mass-assignable because the settings modal saves
        // them as one validated array — every value arriving here has been
        // through UpdateMailgunReceiverSettingsRequest.
        'send_enabled',
        'batch_size',
        'selection_order',
        'cooldown_days',
        'only_active',
        // Which source labels this credential may mail; [] or null = all.
        'receiver_sources',
        'message_subject',
        'message_html',
        'message_template',
    ];

    /** Keep the decryptable key out of array/JSON output by default. */
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key'          => 'encrypted',
            'send_enabled'     => 'boolean',
            'only_active'      => 'boolean',
            'batch_size'       => 'integer',
            'cooldown_days'    => 'integer',
            'last_run_at'      => 'datetime',
            // The authored fields behind message_html — see MailgunReceiverTemplate.
            'message_template' => 'array',
            // A list of source labels, or null for every source.
            'receiver_sources' => 'array',
        ];
    }

    /** Schedules configured to send through this credential. */
    public function emailSchedules(): HasMany
    {
        return $this->hasMany(EmailSchedule::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * True when this credential can actually authenticate a send.
     *
     * Sender identity is deliberately NOT part of the test: a credential with
     * no from_address is complete, because the site template supplies one.
     */
    public function canAuthenticate(): bool
    {
        return $this->plainKey() !== '' && trim((string) $this->domain) !== '';
    }

    /**
     * The decrypted key, or '' when it cannot be read.
     *
     * Decrypting can THROW — an APP_KEY rotation leaves old ciphertext
     * undecryptable — and both callers of this run inside listings that must not
     * die for one bad row: {@see maskedKey()} is rendered for every credential in
     * the admin table, and {@see canAuthenticate()} is called alongside it. An
     * unreadable key is reported as unusable, which is exactly what it is, rather
     * than 500-ing the whole page. The same guard is applied for the same reason
     * in {@see \App\Support\Mail\MailCredential}.
     */
    private function plainKey(): string
    {
        try {
            return trim((string) $this->api_key);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * A safe, non-reversible preview for admin display, e.g. "abc123…7f9e".
     * Never reveals enough of the key to be usable.
     */
    public function maskedKey(): string
    {
        $key = $this->plainKey();

        if ($key === '') {
            return '';
        }

        $head = substr($key, 0, 6);
        $tail = strlen($key) > 4 ? substr($key, -4) : '';

        return $head . '…' . $tail;
    }

    // ── ReceiverCampaignCredential ───────────────────────────────────────────
    // Shared with SmtpCredential so the selector, the daily claim, the message
    // and the send loop never learn which channel they are running.

    public function campaignBatchSize(): int
    {
        return max(1, (int) $this->batch_size);
    }

    public function campaignSelectionOrder(): string
    {
        return (string) $this->selection_order;
    }

    public function campaignCooldownDays(): ?int
    {
        return $this->cooldown_days === null ? null : (int) $this->cooldown_days;
    }

    /**
     * Sources this credential may mail — empty means both.
     *
     * Stored as JSON so the choice is a SET: one credential can be pointed at
     * our clients, at external ones, or at both without needing two
     * credentials. Anything that is not a {@see ReceiverSource} is dropped, so
     * a stale value left in the column can never widen or empty an audience.
     *
     * @return list<string>
     */
    public function campaignSourceLabels(): array
    {
        $sources = $this->receiver_sources;

        if (! is_array($sources)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($source): ?string => ReceiverSource::parse(is_string($source) ? $source : null)?->value,
            $sources,
        ))));
    }

    public function campaignSubject(): string
    {
        return (string) $this->message_subject;
    }

    public function campaignHtml(): string
    {
        return (string) $this->message_html;
    }

    /** @return array<string, mixed>|null */
    public function campaignTemplate(): ?array
    {
        return $this->message_template;
    }

    /**
     * Reference-only for this channel, and deliberately still returned.
     *
     * The mailable treats a null/empty From as "let the transport decide", which
     * for Mailgun means the site template's sender — the behaviour that predates
     * this interface. Returning the column does not change that; it just stops
     * the interface having a Mailgun-shaped hole in it.
     */
    public function campaignFromAddress(): ?string
    {
        return $this->from_address;
    }

    public function campaignFromName(): ?string
    {
        return $this->from_name;
    }

    public function campaignLabel(): string
    {
        return (string) $this->name;
    }

    public function campaignChannel(): string
    {
        return 'mailgun';
    }

    public function campaignHistoryTable(): string
    {
        return 'mailgun_receiver_sends';
    }

    public function campaignHistoryColumn(): string
    {
        return 'mailgun_key_id';
    }
}
