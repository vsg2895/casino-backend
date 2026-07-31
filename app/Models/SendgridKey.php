<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stored SendGrid API key that can authenticate scheduled promotion sends.
 *
 * The `api_key` is cast `encrypted`, so it is written to the DB encrypted and
 * decrypted transparently when used to build the SendGrid transport. It must
 * NEVER be returned raw by any API Resource — see {@see maskedKey()}.
 */
class SendgridKey extends Model
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    protected $fillable = [
        'name',
        'api_key',
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

    /** Schedules configured to send through this key. */
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
     * A safe, non-reversible preview for admin display, e.g. "SG.abcd…WXYZ".
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
