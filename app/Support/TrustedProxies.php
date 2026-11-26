<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves the trusted-proxy list for bootstrap/app.php.
 *
 * A class rather than an inline `config()` call because the bootstrap file
 * runs before the container is ready to be leaned on, and because the "is this
 * address one of ours" question is needed in a second place: the visitor
 * tracker only believes `CF-Connecting-IP` when the connection genuinely came
 * from Cloudflare, and that check lives in trustsConnectionFrom().
 */
final class TrustedProxies
{
    /**
     * The CIDRs Laravel will accept forwarding headers from.
     *
     * @return string|array<int, string>
     */
    public static function list(): string|array
    {
        $config = self::config();
        $override = $config['trusted'] ?? null;

        if ($override === '*') {
            return '*';
        }

        if (is_string($override) && trim($override) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $override))));
        }

        $ranges = $config['local'] ?? [];

        if ($config['cloudflare_enabled'] ?? true) {
            $ranges = array_merge($ranges, $config['cloudflare'] ?? []);
        }

        return array_values($ranges);
    }

    /**
     * Whether a connection from $ip is one of our own proxies.
     *
     * This is what gates the non-standard vendor headers. Symfony handles
     * `X-Forwarded-*` itself once the proxy list is set, but `CF-Connecting-IP`
     * and `True-Client-IP` are vendor headers it knows nothing about — so any
     * client can send them. Believing one without checking who the connection
     * came from would reintroduce exactly the forgery that restricting the
     * proxy list just closed.
     */
    public static function trustsConnectionFrom(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $list = self::list();

        if ($list === '*') {
            return true;
        }

        foreach ($list as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }


    /**
     * The proxy config, read from its FILE rather than through config().
     *
     * bootstrap/app.php's middleware closure runs while the kernel is being
     * resolved — before the configuration repository is bound — so calling
     * config() here fatals with "Target class [config] does not exist" and
     * takes the whole application down, CLI included. Reading the file
     * directly is the only thing available this early, and it is cached
     * statically so the require happens once per process.
     *
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        static $cache = null;

        if ($cache === null) {
            $path  = __DIR__.'/../../config/proxies.php';
            $cache = is_file($path) ? (array) require $path : [];
        }

        return $cache;
    }

    /** CIDR containment for both address families. */
    public static function inRange(string $ip, string $range): bool
    {
        if (! str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        // Different families never match, and an unparseable address is not in
        // any range — never a reason to treat it as trusted.
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes     = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }
}
