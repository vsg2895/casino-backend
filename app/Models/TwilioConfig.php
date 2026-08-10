<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stored Twilio credential that can authenticate bulk SMS sends.
 *
 * Sibling of {@see SendgridKey} and {@see MailgunKey}, intentionally the same
 * shape so the admin UI and the credential dropdown work the same way for every
 * provider. What differs is Twilio's own model: it authenticates an
 * (account_sid, auth_token) pair over HTTP Basic, and every message must name a
 * sender the account owns.
 *
 * The `auth_token` is cast `encrypted`, so it is written to the DB encrypted and
 * decrypted transparently when a request is signed. It must NEVER be returned
 * raw by any API Resource — see {@see maskedToken()}.
 */
class TwilioConfig extends Model
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'name',
        'account_sid',
        'auth_token',
        'from_number',
        'messaging_service_sid',
        'status',
    ];

    /** Keep the decryptable token out of array/JSON output by default. */
    protected $hidden = ['auth_token'];

    protected function casts(): array
    {
        return [
            'auth_token' => 'encrypted',
        ];
    }

    /** SMS sends recorded against this credential. */
    public function smsHistories(): HasMany
    {
        return $this->hasMany(PhoneSmsHistory::class);
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
     * Whether this credential can actually send: it needs a sender identity.
     *
     * Checked before a fan-out rather than discovered per message, so a
     * misconfigured credential fails once with a clear reason instead of
     * producing one Twilio 21603 per recipient.
     */
    public function hasSender(): bool
    {
        return $this->senderIdentity() !== null;
    }

    /**
     * The sender to send as, and which Twilio parameter carries it.
     *
     * A Messaging Service wins when both are set: at bulk volume it is the
     * better sender (number pooling, sticky sender, per-service opt-out
     * handling), so if an operator has configured one, that is the intent.
     *
     * @return array{0: string, 1: string}|null  [parameter, value]
     */
    public function senderIdentity(): ?array
    {
        $service = trim((string) $this->messaging_service_sid);

        if ($service !== '') {
            return ['MessagingServiceSid', $service];
        }

        $number = trim((string) $this->from_number);

        return $number === '' ? null : ['From', $number];
    }

    /** How the sender reads in the admin UI and in logs. */
    public function senderLabel(): string
    {
        $identity = $this->senderIdentity();

        if ($identity === null) {
            return '';
        }

        return $identity[1];
    }

    /**
     * A safe, non-reversible preview for admin display, e.g. "abc123…7f9e".
     * Never reveals enough of the token to be usable.
     */
    public function maskedToken(): string
    {
        $token = (string) $this->auth_token;

        if ($token === '') {
            return '';
        }

        $head = substr($token, 0, 6);
        $tail = strlen($token) > 4 ? substr($token, -4) : '';

        return $head . '…' . $tail;
    }

    /**
     * The Account SID is not a secret in the way the token is (it identifies the
     * account, it does not authenticate it), but it is still account-identifying,
     * so the listing shows only enough to tell two credentials apart.
     */
    public function maskedAccountSid(): string
    {
        $sid = (string) $this->account_sid;

        if (strlen($sid) <= 10) {
            return $sid;
        }

        return substr($sid, 0, 6) . '…' . substr($sid, -4);
    }
}
