<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\ReceiverCampaignCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * An own SMTP server that can run a campaign against the Mailgun receiver list.
 *
 * Sibling of {@see MailgunKey}, not a variant of it. Both implement
 * {@see ReceiverCampaignCredential}, and everything downstream of that interface
 * — selection, the daily claim, the message, the history — is shared. The only
 * real difference is authentication: a host/port/username/password pair instead
 * of a (domain, API key) pair.
 *
 * The `password` is cast `encrypted`, so it is written encrypted and decrypted
 * transparently when the transport is built. It must NEVER be returned raw by an
 * API Resource — see {@see maskedPassword()}.
 *
 * Unlike {@see MailgunKey}, `from_address` IS read at send time. A Mailgun
 * credential takes its sender from the site template; an own SMTP server has no
 * other source of identity, and most reject a From that is not on their own
 * domain — which is exactly the "sends, no error, never arrives" failure the
 * project's mail troubleshooting documents.
 *
 * There is no `send_enabled`: these credentials are driven only by the admin's
 * "Run now" button, never by the scheduler.
 */
class SmtpCredential extends Model implements ReceiverCampaignCredential
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /**
     * An ordinary mailbox on a mail server we control.
     */
    public const string TYPE_OWN_SMTP = 'own_smtp';

    /**
     * Mailgun's SMTP gateway.
     *
     * Worth distinguishing even though both go out over plain SMTP: the password
     * on these rows is Mailgun's SMTP password, which LOOKS like an API key and
     * is not one, and mistaking the two is how someone ends up pasting an API
     * key into a password field that then fails to authenticate.
     */
    public const string TYPE_MAILGUN_SMTP = 'mailgun_smtp';

    /** @var list<string> */
    public const array TYPES = [self::TYPE_OWN_SMTP, self::TYPE_MAILGUN_SMTP];

    public const string ENCRYPTION_SSL = 'ssl';
    public const string ENCRYPTION_TLS = 'tls';
    public const string ENCRYPTION_NONE = 'none';

    /** @var list<string> */
    public const array ENCRYPTIONS = [self::ENCRYPTION_SSL, self::ENCRYPTION_TLS, self::ENCRYPTION_NONE];

    protected $fillable = [
        'name',
        'type',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_address',
        'from_name',
        'status',
        // Receiver targeting. Mass-assignable because the settings modal saves
        // them as one validated array — every value arriving here has been
        // through UpdateSmtpReceiverSettingsRequest.
        'batch_size',
        'selection_order',
        'cooldown_days',
        'only_active',
        'message_subject',
        'message_html',
        'message_template',
    ];

    /** Keep the decryptable password out of array/JSON output by default. */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password'         => 'encrypted',
            'port'             => 'integer',
            'only_active'      => 'boolean',
            'batch_size'       => 'integer',
            'cooldown_days'    => 'integer',
            'last_run_at'      => 'datetime',
            'message_template' => 'array',
        ];
    }

    /** This credential's per-address send history. */
    public function sends(): HasMany
    {
        return $this->hasMany(SmtpReceiverSend::class);
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
     * Sender identity IS part of the test here, unlike {@see MailgunKey}: an own
     * SMTP server supplies no From of its own, so a credential without one cannot
     * produce a deliverable message.
     */
    public function canAuthenticate(): bool
    {
        return trim((string) $this->host) !== ''
            && trim((string) $this->username) !== ''
            && $this->plainPassword() !== ''
            && trim((string) $this->from_address) !== '';
    }

    /**
     * The decrypted password, or '' when it cannot be read.
     *
     * Decrypting can THROW — an APP_KEY rotation leaves old ciphertext
     * undecryptable — and callers run inside listings that must not die for one
     * bad row. An unreadable password is reported as unusable, which is what it
     * is, rather than 500-ing the credentials page. Same guard, same reason, as
     * {@see MailgunKey::plainKey()}.
     */
    public function plainPassword(): string
    {
        try {
            return trim((string) $this->password);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * A length-only placeholder for admin display.
     *
     * Deliberately reveals NOTHING of the value — not even the first characters,
     * the way {@see MailgunKey::maskedKey()} does for an API key. An API key is a
     * single-purpose token; this is very often a mailbox password reused
     * elsewhere, so no prefix of it should ever reach a browser.
     */
    public function maskedPassword(): string
    {
        $length = strlen($this->plainPassword());

        return $length === 0 ? '' : str_repeat('•', min($length, 12));
    }

    // ── ReceiverCampaignCredential ───────────────────────────────────────────

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
        return 'smtp';
    }

    public function campaignHistoryTable(): string
    {
        return 'smtp_receiver_sends';
    }

    public function campaignHistoryColumn(): string
    {
        return 'smtp_credential_id';
    }
}
