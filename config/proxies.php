<?php

declare(strict_types=1);

/*
 * WHICH PROXIES MAY REWRITE THE CLIENT ADDRESS.
 *
 * This used to be `trustProxies(at: '*')`, which means "believe any
 * X-Forwarded-For from anyone". That is not a theoretical weakness: with it,
 * `curl -H 'X-Forwarded-For: 1.2.3.4'` makes every request appear to come from
 * 1.2.3.4, which forges visitor records and — because Laravel's throttle
 * middleware keys on the client address — lets one caller walk around every
 * rate limit by changing a header.
 *
 * The list below is the chain this platform actually has: a local nginx in
 * front of PHP, with Cloudflare in front of that. An address NOT in it has its
 * forwarding headers ignored and is taken at face value, which is the correct
 * default for anything reaching the origin directly.
 *
 * `TRUSTED_PROXIES=*` restores the old behaviour if a deploy ever needs it in
 * a hurry; it is deliberately the only way to get back to trusting everything.
 */
return [

    /*
     * The CIDRs whose forwarding headers are believed. A comma-separated
     * TRUSTED_PROXIES replaces the whole list; '*' trusts everything.
     */
    'trusted' => env('TRUSTED_PROXIES'),

    /*
     * The local reverse proxy. nginx terminates TLS on the same host and
     * proxies to PHP over the loopback, so without these the client address
     * collapses to 127.0.0.1 for every request on the box.
     */
    'local' => [
        '127.0.0.1/32',
        '::1/128',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        'fc00::/7',
    ],

    /*
     * Cloudflare's published edge ranges (cloudflare.com/ips).
     *
     * STALE RANGES FAIL CLOSED, which is the safe direction: a new Cloudflare
     * range that is missing here means requests through it are read as direct,
     * so the visitor address becomes the edge address — wrong, but never
     * forgeable. Refresh with:
     *
     *   curl -s https://www.cloudflare.com/ips-v4 https://www.cloudflare.com/ips-v6
     *
     * Only consulted when `cloudflare` is true.
     */
    'cloudflare_enabled' => (bool) env('TRUST_CLOUDFLARE', true),

    'cloudflare' => [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],
];
