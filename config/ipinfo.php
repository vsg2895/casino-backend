<?php

declare(strict_types=1);

/*
 * IP intelligence for Special Offer visit tracking.
 *
 * The driver is an env because it legitimately differs per environment — the
 * test suite must never make a paid call — while the cache TTL and the
 * timeout are literals, because changing them silently changes cost and
 * request latency rather than behaviour an operator chose.
 */
return [

    /*
     * `ipinfo` talks to ipinfo.io. `null` resolves nothing and is what tests
     * and local development use: tracking still records every address, it
     * simply never attributes geography to one.
     */
    'driver' => env('IP_INFO_DRIVER', 'null'),

    'ipinfo' => [
        'token'    => env('IPINFO_TOKEN'),
        'base_url' => 'https://ipinfo.io',
    ],

    /*
     * Seven days. An address's geography and its ASN change on the order of
     * months, so a shorter TTL buys nothing and is billed per lookup; a much
     * longer one would keep a stale country after a reallocation.
     */
    'cache_days' => 7,

    /*
     * Deliberately short. This runs inside a queued job, but a provider that
     * has gone slow must not hold a worker for a minute per address while a
     * campaign is waiting behind it on the same queue.
     */
    'timeout_seconds' => 5,

    /*
     * ipinfo reports privacy flags only on its paid plans. On the free tier
     * the fields are simply absent, which is NOT the same as "false" — so the
     * service leaves the flags alone rather than writing a confident `false`
     * it has no evidence for. See IpInfoService::mapPrivacy().
     */
];
