<?php

declare(strict_types=1);

namespace App\Models;

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
class MailgunKey extends Model
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    public const string REGION_US = 'us';
    public const string REGION_EU = 'eu';

    /** @var list<string> */
    public const array REGIONS = [self::REGION_US, self::REGION_EU];

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
}
