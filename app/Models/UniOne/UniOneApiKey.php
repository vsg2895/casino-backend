<?php

declare(strict_types=1);

namespace App\Models\UniOne;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A stored UniOne API key.
 *
 * Mirrors SendgridKey's three defences and adds a fourth:
 *   1. `encrypted` cast   — never plaintext at rest
 *   2. `$hidden`          — never in array/JSON output by accident
 *   3. `maskedKey()`      — the only representation a resource may emit
 *   4. `$webhook_secret`  — also encrypted, also hidden
 *
 * Nothing in this class or anywhere downstream logs the decrypted value. The
 * client puts it in a header and never in a message.
 */
class UniOneApiKey extends Model
{
    use SoftDeletes;

    protected $table = 'unione_api_keys';

    public const string TYPE_USER = 'user';

    public const string TYPE_PROJECT = 'project';

    /** @var list<string> */
    public const array TYPES = [self::TYPE_USER, self::TYPE_PROJECT];

    public const string REGION_AUTO = 'auto';

    public const string REGION_EU1 = 'eu1';

    public const string REGION_US1 = 'us1';

    /** @var list<string> */
    public const array REGIONS = [self::REGION_AUTO, self::REGION_EU1, self::REGION_US1];

    /**
     * Base URLs per region, from the OpenAPI spec's `servers` block.
     *
     * `auto` is the global host, which routes to whichever datacenter the
     * account lives on. Kept as a literal rather than an env var because the
     * brief forbids .env additions AND because a wrong value here breaks every
     * send — it is not an environment preference.
     */
    public const array REGION_BASE_URLS = [
        self::REGION_AUTO => 'https://api.unione.io/en/transactional/api/v1',
        self::REGION_EU1  => 'https://eu1.unione.io/en/transactional/api/v1',
        self::REGION_US1  => 'https://us1.unione.io/en/transactional/api/v1',
    ];

    protected $fillable = [
        'name',
        'api_key',
        'key_type',
        'project_id',
        'region',
        'base_url',
        'is_active',
        'default_from_email',
        'default_from_name',
        'track_links',
        'track_read',
        'timeout_seconds',
        'notes',
    ];

    /**
     * `is_default` is NOT fillable.
     *
     * Exactly one row may hold it, and that invariant is maintained in a
     * transaction by UniOneKeyService. A fillable column would let a plain
     * update set a second default and leave the send path picking arbitrarily.
     */
    protected $hidden = ['api_key', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'api_key'          => 'encrypted',
            'webhook_secret'   => 'encrypted',
            'is_active'        => 'boolean',
            'is_default'       => 'boolean',
            'track_links'      => 'boolean',
            'track_read'       => 'boolean',
            'timeout_seconds'  => 'integer',
            'last_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $key): void {
            // A URL path token, generated once. See the migration for why this
            // is not a signature secret.
            if (($key->webhook_secret ?? '') === '') {
                $key->webhook_secret = Str::random(48);
            }
        });

        static::saving(function (self $key): void {
            // Derived unless an operator has typed one. A blank base_url would
            // make every request fail with an unhelpful "invalid URL".
            if (trim((string) $key->base_url) === '') {
                $key->base_url = self::REGION_BASE_URLS[$key->region] ?? self::REGION_BASE_URLS[self::REGION_EU1];
            }

            // A user key has no project. Clearing it here rather than trusting
            // the form stops a stale id travelling with a retyped key.
            if ($key->key_type === self::TYPE_USER) {
                $key->project_id = null;
            }
        });
    }

    public function sends(): HasMany
    {
        return $this->hasMany(UniOneSend::class, 'unione_api_key_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /**
     * A safe preview: the last 4 characters only.
     *
     * The brief asks for Replace, never Reveal — so this deliberately shows less
     * than SendgridKey's `SG.abcd…WXYZ`. A UniOne key has no distinguishing
     * prefix, so a head fragment would leak entropy for no operator benefit.
     */
    public function maskedKey(): string
    {
        $key = (string) $this->api_key;

        if ($key === '') {
            return '';
        }

        return '••••••••' . substr($key, -4);
    }

    /** Whether this key passed its last verification. */
    public function isVerified(): bool
    {
        return $this->last_verified_at !== null
            && str_starts_with((string) $this->last_verify_status, 'ok');
    }

    /** The endpoint for one API path, e.g. `email/send.json`. */
    public function endpoint(string $path): string
    {
        return rtrim((string) $this->base_url, '/') . '/' . ltrim($path, '/');
    }
}
