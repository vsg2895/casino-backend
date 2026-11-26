<?php

declare(strict_types=1);

namespace App\Services\IpInfo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ipinfo.io.
 *
 * Chosen because its free tier already answers the question this feature
 * exists for — country, city, ASN and organisation — and its paid `privacy`
 * object answers the VPN question directly, which is the legitimate way to
 * detect a masked visitor without trying to unmask one.
 */
final class IpInfoIoDriver implements IpInfoDriver
{
    public function __construct(
        private readonly ?string $token,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    public function isEnabled(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    public function lookup(string $ip): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->withToken($this->token)
                ->get("{$this->baseUrl}/{$ip}/json");

            if (! $response->successful()) {
                // A quota wall is the expected steady state on a free plan, so
                // it is logged at warning and never raised: tracking must not
                // depend on a billing relationship being current.
                Log::warning('ipinfo lookup failed', ['ip' => $ip, 'status' => $response->status()]);

                return null;
            }

            /** @var array<string, mixed> $data */
            $data = $response->json();

            if (! is_array($data) || isset($data['bogon'])) {
                return null;
            }

            return $this->map($data);
        } catch (Throwable $e) {
            Log::warning('ipinfo lookup threw', ['ip' => $ip, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function map(array $data): array
    {
        // "loc" is "lat,lng" in one string; absent for addresses ipinfo cannot place.
        $lat = $lng = null;
        if (isset($data['loc']) && is_string($data['loc']) && str_contains($data['loc'], ',')) {
            [$lat, $lng] = array_map('trim', explode(',', $data['loc'], 2));
            $lat = is_numeric($lat) ? (float) $lat : null;
            $lng = is_numeric($lng) ? (float) $lng : null;
        }

        // The ASN lives in two different shapes depending on plan: a structured
        // `asn` object, or an `org` string that begins "AS15169 Google LLC".
        $asn = null;
        $org = isset($data['org']) && is_string($data['org']) ? $data['org'] : null;

        if (isset($data['asn']) && is_array($data['asn']) && isset($data['asn']['asn'])) {
            $asn = (string) $data['asn']['asn'];
        } elseif ($org !== null && preg_match('/^(AS\d+)\s*/i', $org, $m) === 1) {
            $asn = strtoupper($m[1]);
            $org = trim(substr($org, strlen($m[0]))) ?: $org;
        }

        $privacy = $this->mapPrivacy($data);

        return [
            'country_code' => isset($data['country']) && is_string($data['country']) ? strtoupper($data['country']) : null,
            'country'      => isset($data['country_name']) && is_string($data['country_name']) ? $data['country_name'] : null,
            'region'       => isset($data['region']) && is_string($data['region']) ? $data['region'] : null,
            'city'         => isset($data['city']) && is_string($data['city']) ? $data['city'] : null,
            'latitude'     => $lat,
            'longitude'    => $lng,
            'timezone'     => isset($data['timezone']) && is_string($data['timezone']) ? $data['timezone'] : null,
            'isp'          => $org,
            'org'          => $org,
            'asn'          => $asn,
            'is_proxy'     => $privacy['is_proxy'],
            'is_vpn'       => $privacy['is_vpn'],
            'is_hosting'   => $privacy['is_hosting'],
            'raw'          => $data,
        ];
    }

    /**
     * ABSENT IS NOT FALSE, and this is the one place it matters.
     *
     * The `privacy` object is a paid feature. On a free token the keys are
     * simply missing, and mapping that to `false` would have the admin state
     * "not a VPN" about every visitor on the strength of a plan the account
     * does not have. Null means "not established", and the caller leaves the
     * stored flag untouched.
     *
     * @param  array<string, mixed>  $data
     * @return array{is_proxy: bool|null, is_vpn: bool|null, is_hosting: bool|null}
     */
    private function mapPrivacy(array $data): array
    {
        $privacy = $data['privacy'] ?? null;

        if (! is_array($privacy)) {
            return ['is_proxy' => null, 'is_vpn' => null, 'is_hosting' => null];
        }

        return [
            'is_proxy'   => array_key_exists('proxy', $privacy) ? (bool) $privacy['proxy'] : null,
            'is_vpn'     => array_key_exists('vpn', $privacy) ? (bool) $privacy['vpn'] : null,
            'is_hosting' => array_key_exists('hosting', $privacy) ? (bool) $privacy['hosting'] : null,
        ];
    }
}
