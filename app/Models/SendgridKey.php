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
 * A stored SendGrid API key that can authenticate scheduled promotion sends
 * and run campaigns against the SendGrid receiver list.
 *
 * The `api_key` is cast `encrypted`, so it is written to the DB encrypted and
 * decrypted transparently when used to build the SendGrid transport. It must
 * NEVER be returned raw by any API Resource — see {@see maskedKey()}.
 *
 * Receiver campaigns use the SAME machinery as {@see MailgunKey} and
 * {@see SmtpCredential}, through {@see ReceiverCampaignCredential}; what this
 * key names differently is the list ({@see SendgridReceiver}) and the history
 * table. Like an own SMTP server, a campaign needs `from_address`: there is no
 * site template behind a receiver message to inherit a sender from, and
 * SendGrid rejects a From that is not a verified sender.
 */
class SendgridKey extends Model implements ReceiverCampaignCredential
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /**
     * The columns the listing resource actually renders.
     *
     * `message_html` is LONGTEXT and `message_template` / `receiver_sources` are
     * JSON; none of them reaches {@see \App\Http\Resources\SendgridKeyResource}.
     * The listing is unpaginated and also feeds the schedule dropdown, so a
     * `SELECT *` would pull one campaign body per key every time either opens.
     * Same reasoning as {@see MailgunKey::LIST_COLUMNS}.
     *
     * @var list<string>
     */
    public const array LIST_COLUMNS = [
        'id',
        'name',
        'api_key',
        'from_address',
        'from_name',
        'webhook_verification_key',
        'status',
        'created_at',
        'updated_at',
    ];

    protected $fillable = [
        'name',
        'api_key',
        'from_address',
        'from_name',
        // Signed Event Webhook public key — see SendgridWebhookController.
        'webhook_verification_key',
        'status',
        // Receiver targeting. Mass-assignable because the settings modal saves
        // them as one validated array — every value arriving here has been
        // through UpdateSendgridReceiverSettingsRequest.
        'batch_size',
        'selection_order',
        'cooldown_days',
        // Which source labels this key may mail; [] or null = all.
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
            'batch_size'       => 'integer',
            'cooldown_days'    => 'integer',
            'last_run_at'      => 'datetime',
            // The authored fields behind message_html — see MailgunReceiverTemplate.
            'message_template' => 'array',
            // A list of source labels, or null for every source.
            'receiver_sources' => 'array',
        ];
    }

    /** Schedules configured to send through this key. */
    public function emailSchedules(): HasMany
    {
        return $this->hasMany(EmailSchedule::class);
    }

    public function receiverSends(): HasMany
    {
        return $this->hasMany(SendgridReceiverSend::class);
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
     * True when the key can authenticate a send.
     *
     * The sender is NOT part of this, because schedules and the test button
     * take their sender from a site template. The receiver campaign checks
     * `from_address` itself — see SendSendgridReceiverCampaignJob::blockedReason().
     */
    public function canAuthenticate(): bool
    {
        return $this->plainKey() !== '';
    }

    /**
     * The decrypted key, or '' when it cannot be read.
     *
     * Decrypting can THROW after an APP_KEY rotation, and the callers run inside
     * listings that must not die for one bad row. Same guard as
     * {@see MailgunKey::plainKey()}.
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
     * A safe, non-reversible preview for admin display, e.g. "SG.abcd…WXYZ".
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

    /** @return class-string<SendgridReceiver> */
    public function campaignReceiverModel(): string
    {
        return SendgridReceiver::class;
    }

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
     * Sources this key may mail — empty means both. Anything that is not a
     * {@see ReceiverSource} is dropped, so a stale value can never widen or
     * empty an audience.
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
        return 'sendgrid';
    }

    public function campaignHistoryTable(): string
    {
        return 'sendgrid_receiver_sends';
    }

    public function campaignHistoryColumn(): string
    {
        return 'sendgrid_key_id';
    }
}
