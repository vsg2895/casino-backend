<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One address claimed for one visit, with the claim's provenance attached.
 *
 * `type` says WHERE the value came from (which header, or which client probe)
 * and `source` says WHO said it. Both matter and neither implies the other: a
 * `cf_connecting_ip` is only as trustworthy as the proxy configuration that
 * admitted it, and a `client_ipv4` is a claim by the page, which anyone can
 * forge by POSTing whatever they like.
 */
class SpecialOfferVisitIp extends Model
{
    use HasFactory;

    /** Header-derived types, in the order primary_ip resolution prefers them. */
    public const array SERVER_TYPES = [
        'cf_connecting_ip',
        'true_client_ip',
        'x_real_ip',
        'x_forwarded_for',
        'x_client_ip',
        'forwarded',
        'remote_addr',
    ];

    public const array CLIENT_TYPES = [
        'client_ipv4',
        'client_ipv6',
    ];

    protected $fillable = [
        'special_offer_visit_id',
        'ip',
        'ip_version',
        'is_private',
        'is_mdns',
        'type',
        'source',
        'country_code',
        'country',
        'region',
        'city',
        'latitude',
        'longitude',
        'timezone',
        'isp',
        'org',
        'asn',
        'is_proxy',
        'is_vpn',
        'is_hosting',
        'geo_raw',
        'geo_resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'ip_version'      => 'integer',
            'is_private'      => 'boolean',
            'is_mdns'         => 'boolean',
            'is_proxy'        => 'boolean',
            'is_vpn'          => 'boolean',
            'is_hosting'      => 'boolean',
            'latitude'        => 'float',
            'longitude'       => 'float',
            'geo_raw'         => 'array',
            'geo_resolved_at' => 'datetime',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(SpecialOfferVisit::class, 'special_offer_visit_id');
    }

    /**
     * Classify a raw value into the columns that describe it, without deciding
     * whether to keep it — that is the caller's job.
     *
     * Returns null for anything that is neither an address nor an mDNS name,
     * which is how garbage from a forged phase-2 payload is dropped.
     *
     * @return array{ip: string, ip_version: int|null, is_private: bool, is_mdns: bool}|null
     */
    public static function classify(string $value): ?array
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 255) {
            return null;
        }

        // An mDNS hostname is what a browser hands back INSTEAD of a local
        // address. It is not an address and must never reach a geo lookup.
        if (str_ends_with(strtolower($value), '.local')) {
            return [
                'ip'         => $value,
                'ip_version' => null,
                'is_private' => true,
                'is_mdns'    => true,
            ];
        }

        // Strip an IPv6 zone index (fe80::1%eth0) before validating: it is a
        // local interface name, meaningless off this machine.
        $bare = str_contains($value, '%') ? substr($value, 0, strpos($value, '%')) : $value;

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return [
                'ip'         => $value,
                'ip_version' => 4,
                'is_private' => self::isPrivate($bare),
                'is_mdns'    => false,
            ];
        }

        if (filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return [
                'ip'         => $value,
                'ip_version' => 6,
                'is_private' => self::isPrivate($bare),
                'is_mdns'    => false,
            ];
        }

        return null;
    }

    /**
     * Private, reserved, loopback or link-local — anything a geo provider
     * cannot place and that must never be billed as a lookup.
     *
     * PHP's own NO_PRIV_RANGE|NO_RES_RANGE does the work for both families;
     * `filter_var` returning false means the address fell into one of them.
     */
    public static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /** Addresses a geo provider can usefully be asked about. */
    public function scopeGeoResolvable(Builder $query): Builder
    {
        return $query->where('is_private', false)->where('is_mdns', false);
    }

    /** True once a lookup has been attempted, successful or not. */
    public function hasGeo(): bool
    {
        return $this->geo_resolved_at !== null;
    }
}
