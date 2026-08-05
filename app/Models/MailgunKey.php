<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'status',
    ];

    /** Keep the decryptable key out of array/JSON output by default. */
    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
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
     * A safe, non-reversible preview for admin display, e.g. "abc123…7f9e".
     * Never reveals enough of the key to be usable.
     */
    public function maskedKey(): string
    {
        $key = (string) $this->api_key;

        if ($key === '') {
            return '';
        }

        $head = substr($key, 0, 6);
        $tail = strlen($key) > 4 ? substr($key, -4) : '';

        return $head . '…' . $tail;
    }
}
