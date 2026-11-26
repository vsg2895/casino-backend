<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SpecialOfferVisitIp;
use App\Support\TrustedProxies;
use Illuminate\Http\Request;

/**
 * Turns one request into the set of addresses it claims to come from.
 *
 * THE RULE THIS SERVICE EXISTS TO ENFORCE: a forwarding header is evidence
 * only when the connection carrying it came from a proxy we run. Symfony
 * already applies that rule to the standard `X-Forwarded-*` family once
 * config/proxies.php is set, but `CF-Connecting-IP`, `True-Client-IP`,
 * `X-Real-IP` and `X-Client-IP` are vendor conventions it knows nothing about.
 * Reading those unconditionally — which is what almost every "get the real IP"
 * snippet on the internet does — hands every visitor the ability to choose
 * their own recorded address, and with it their recorded country.
 *
 * So every vendor header below is gated on trustsConnectionFrom(REMOTE_ADDR),
 * and REMOTE_ADDR itself is always recorded, because it is the only value in
 * the whole set that cannot be forged.
 */
final class VisitorIpResolver
{
    /**
     * Vendor headers, in the order they win when choosing primary_ip.
     *
     * Cloudflare first because it is the outermost proxy in this platform's
     * chain and therefore the one that saw the real client; the rest follow in
     * descending specificity, with the multi-entry X-Forwarded-For last of the
     * headers because its leftmost entry is the most client-controlled value
     * of all — a client can simply send one and have it prepended.
     */
    private const array VENDOR_HEADERS = [
        /*
         * FIRST, and it has to be: everything below it is rewritten by
         * Cloudflare when the API is reached at its public hostname. The site's
         * Next.js server is the last component that saw the visitor directly,
         * so it states the address here and nothing in the path alters it.
         */
        'X-Visitor-IP'     => SpecialOfferVisitIp::SITE_REPORTED,
        'CF-Connecting-IP' => 'cf_connecting_ip',
        'True-Client-IP'   => 'true_client_ip',
        'X-Real-IP'        => 'x_real_ip',
        'X-Client-IP'      => 'x_client_ip',
    ];

    /** Headers captured verbatim for the audit trail, interpreted or not. */
    private const array AUDIT_HEADERS = [
        'X-Visitor-IP',
        'CF-Connecting-IP',
        'CF-IPCountry',
        'True-Client-IP',
        'X-Real-IP',
        'X-Forwarded-For',
        'X-Client-IP',
        'Forwarded',
        'Via',
    ];

    /**
     * @return array{
     *     primary: string|null,
     *     trusted: bool,
     *     country_hint: string|null,
     *     headers_raw: array<string, string|null>,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function resolve(Request $request): array
    {
        $remoteAddr = $request->server('REMOTE_ADDR');
        $trusted    = TrustedProxies::trustsConnectionFrom(is_string($remoteAddr) ? $remoteAddr : null);

        $headersRaw = ['REMOTE_ADDR' => is_string($remoteAddr) ? $remoteAddr : null];
        foreach (self::AUDIT_HEADERS as $header) {
            $headersRaw[$header] = $request->header($header);
        }

        /** @var array<string, array<string, mixed>> $rows keyed by "ip|type" to honour the table's unique key */
        $rows = [];

        $add = function (?string $value, string $type) use (&$rows): void {
            if ($value === null) {
                return;
            }

            $classified = SpecialOfferVisitIp::classify($value);

            if ($classified === null) {
                return;
            }

            $rows[$classified['ip'].'|'.$type] = $classified + [
                'type'   => $type,
                'source' => 'server_seen',
            ];
        };

        // Always. The transport-level peer is the one unforgeable fact here.
        $add(is_string($remoteAddr) ? $remoteAddr : null, 'remote_addr');

        $candidates = [];

        if ($trusted) {
            foreach (self::VENDOR_HEADERS as $header => $type) {
                $value = $request->header($header);
                $add($value, $type);

                if (is_string($value) && $this->isUsablePublic($value)) {
                    $candidates[$type] ??= trim($value);
                }
            }

            // EVERY entry, each as its own row: an X-Forwarded-For is a path,
            // "client, proxy1, proxy2", and collapsing it to one value throws
            // away the only record of what the path was.
            foreach ($this->splitForwardedFor($request->header('X-Forwarded-For')) as $entry) {
                $add($entry, 'x_forwarded_for');

                if ($this->isUsablePublic($entry)) {
                    $candidates['x_forwarded_for'] ??= $entry;
                }
            }

            foreach ($this->parseForwarded($request->header('Forwarded')) as $entry) {
                $add($entry, 'forwarded');

                if ($this->isUsablePublic($entry)) {
                    $candidates['forwarded'] ??= $entry;
                }
            }
        }

        $primary = $this->choosePrimary($candidates, is_string($remoteAddr) ? $remoteAddr : null);

        return [
            'primary'      => $primary,
            'trusted'      => $trusted,
            // Cloudflare's own country verdict. Free, already present, and the
            // only geography available before the lookup job runs — so the
            // admin is never completely blank while a queue is backed up.
            'country_hint' => $trusted ? $this->normaliseCountry($request->header('CF-IPCountry')) : null,
            'headers_raw'  => $headersRaw,
            'rows'         => array_values($rows),
        ];
    }

    /**
     * Precedence for the one address the visit is filed under.
     *
     * REMOTE_ADDR is the fallback rather than the preference: behind
     * Cloudflare it is an edge address, which is true but useless. It is still
     * returned when nothing better exists, including when it is private —
     * recording "we only ever saw 127.0.0.1" is more honest than recording
     * nothing and letting the row look like a lookup failure.
     *
     * @param array<string, string> $candidates
     */
    private function choosePrimary(array $candidates, ?string $remoteAddr): ?string
    {
        foreach ([SpecialOfferVisitIp::SITE_REPORTED, 'cf_connecting_ip', 'true_client_ip', 'x_real_ip', 'x_forwarded_for', 'x_client_ip', 'forwarded'] as $type) {
            if (isset($candidates[$type])) {
                return $candidates[$type];
            }
        }

        return $remoteAddr;
    }

    /** A value worth preferring: a real, routable, public address. */
    private function isUsablePublic(string $value): bool
    {
        $classified = SpecialOfferVisitIp::classify($value);

        return $classified !== null && ! $classified['is_private'] && ! $classified['is_mdns'];
    }

    /**
     * @return list<string>
     */
    private function splitForwardedFor(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        // Cap the walk: a hostile proxy chain header can be arbitrarily long,
        // and we would otherwise classify and store every entry of it.
        return array_slice(
            array_values(array_filter(array_map('trim', explode(',', $header)), static fn (string $v): bool => $v !== '')),
            0,
            20
        );
    }

    /**
     * RFC 7239 `Forwarded: for=1.2.3.4;proto=https, for="[2001:db8::1]:443"`.
     *
     * @return list<string>
     */
    private function parseForwarded(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        $out = [];

        foreach (array_slice(explode(',', $header), 0, 20) as $element) {
            foreach (explode(';', $element) as $pair) {
                $pair = trim($pair);

                if (! str_starts_with(strtolower($pair), 'for=')) {
                    continue;
                }

                $value = trim(substr($pair, 4), " \t\"");

                // Bracketed IPv6, optionally with a port: [2001:db8::1]:443
                if (str_starts_with($value, '[')) {
                    $close = strpos($value, ']');
                    if ($close !== false) {
                        $out[] = substr($value, 1, $close - 1);

                        continue;
                    }
                }

                // IPv4 with a port — strip it. A bare IPv6 has many colons and
                // must be left alone, so only split when there is exactly one.
                if (substr_count($value, ':') === 1) {
                    $value = substr($value, 0, (int) strpos($value, ':'));
                }

                if ($value !== '') {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    private function normaliseCountry(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        $code = strtoupper(trim($code));

        // Cloudflare sends XX for "unknown" and T1 for Tor. Neither is a
        // country and neither belongs in a country column.
        if (! preg_match('/^[A-Z]{2}$/', $code) || in_array($code, ['XX', 'T1'], true)) {
            return null;
        }

        return $code;
    }
}
