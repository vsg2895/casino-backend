<?php

declare(strict_types=1);

namespace App\Services\IpInfo;

use App\Models\SpecialOfferVisitIp;
use Illuminate\Support\Facades\Cache;

/**
 * The one way the application asks "where is this address".
 *
 * Owns three things the drivers deliberately do not: which driver is in play,
 * the shared cache, and the refusal to look up an address no provider can
 * answer for. That last one is a cost control as much as a correctness one —
 * a private range or an mDNS hostname is a guaranteed miss, and every miss is
 * still a billed request.
 */
final class IpInfoService
{
    public function __construct(private readonly IpInfoDriver $driver) {}

    public static function make(): self
    {
        return new self(self::driverFromConfig());
    }

    private static function driverFromConfig(): IpInfoDriver
    {
        return match (config('ipinfo.driver', 'null')) {
            'ipinfo' => new IpInfoIoDriver(
                token: config('ipinfo.ipinfo.token'),
                baseUrl: rtrim((string) config('ipinfo.ipinfo.base_url'), '/'),
                timeout: (int) config('ipinfo.timeout_seconds', 5),
            ),
            default => new NullIpInfoDriver(),
        };
    }

    public function isEnabled(): bool
    {
        return $this->driver->isEnabled();
    }

    /**
     * Geography for one address, or null.
     *
     * CACHED ACROSS VISITS, not within one: the same address returns on later
     * visits and across different offers, and the answer is identical every
     * time. The key is the bare address, so a busy visitor is resolved once a
     * week no matter how many pages they open.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $ip): ?array
    {
        if (! $this->isResolvable($ip)) {
            return null;
        }

        if (! $this->driver->isEnabled()) {
            return null;
        }

        $ttl = now()->addDays((int) config('ipinfo.cache_days', 7));

        /*
         * A MISS IS CACHED TOO, as `false`. Without that, an address the
         * provider cannot place is retried on every single visit — the exact
         * addresses that fail are the ones that keep arriving, so the failures
         * compound into the bulk of the bill.
         */
        $cached = Cache::remember(
            "ipinfo:{$ip}",
            $ttl,
            fn (): array|false => $this->driver->lookup($ip) ?? false
        );

        return $cached === false ? null : $cached;
    }

    /**
     * Whether any provider could say something useful about this value.
     *
     * Rejects private, reserved and loopback ranges, and mDNS hostnames.
     */
    public function isResolvable(string $ip): bool
    {
        $classified = SpecialOfferVisitIp::classify($ip);

        return $classified !== null && ! $classified['is_private'] && ! $classified['is_mdns'];
    }
}
