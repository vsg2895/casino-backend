<?php

declare(strict_types=1);

namespace App\Services\IpInfo;

/**
 * One IP-intelligence provider.
 *
 * Implementations return the normalised shape below or null, and MUST NOT
 * throw: a provider outage, a quota wall or a malformed payload has to leave
 * the visit record intact and unresolved rather than failing the job that
 * wraps it. Failures are the service's business, not the caller's.
 */
interface IpInfoDriver
{
    /**
     * @return array{
     *     country_code: string|null, country: string|null, region: string|null, city: string|null,
     *     latitude: float|null, longitude: float|null, timezone: string|null,
     *     isp: string|null, org: string|null, asn: string|null,
     *     is_proxy: bool|null, is_vpn: bool|null, is_hosting: bool|null,
     *     raw: array<string, mixed>
     * }|null
     */
    public function lookup(string $ip): ?array;

    /** Whether this driver can do anything at all — false short-circuits the job. */
    public function isEnabled(): bool;
}
