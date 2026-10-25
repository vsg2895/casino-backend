<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifySiteAccess
{
    /**
     * How long a SUCCESSFUL key check is remembered.
     *
     * A literal rather than config: it is a cost/staleness trade inside the
     * tenancy boundary, not a per-environment setting. Rotation does not wait
     * for it (see verifiedKeyCacheKey()); only a cache entry left behind by a
     * rotated key lives this long, and nothing can ever match it again.
     */
    private const int VERIFIED_TTL_SECONDS = 600;

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('site');
        $providedKey = $request->header('X-Site-Key');

        if (! $slug || ! $providedKey) {
            abort(401, 'Missing site credentials');
        }

        // Looked up on EVERY request, cache or not, so deactivating a site takes
        // effect at once — inactive is 404, the same as unknown, on purpose.
        $site = Site::where('slug', $slug)->where('active', true)->first();

        if (! $site) {
            abort(404, 'Site not found');
        }

        if (! $this->keyMatches($site, (string) $providedKey)) {
            abort(403, 'Invalid site key');
        }

        $request->merge(['_site' => $site]);
        app()->instance('current_site', $site);

        return $next($request);
    }

    /**
     * Hash::check, with successful results remembered.
     *
     * The stored key is a bcrypt hash, and checking it costs ~200 ms of CPU —
     * on every public request, cache hit or not, before SiteCache is even
     * consulted. A site's server presents the same key on every call, so a
     * verified pair is remembered and the next request skips the hash.
     *
     * Only a MATCH is remembered: a wrong key always pays the full bcrypt, so
     * guessing gains nothing. A cache failure in either direction falls back to
     * the bcrypt check — it can neither let a request in nor keep one out.
     */
    private function keyMatches(Site $site, string $providedKey): bool
    {
        $cacheKey = $this->verifiedKeyCacheKey($site, $providedKey);

        try {
            if (Cache::get($cacheKey) === true) {
                return true;
            }
        } catch (Throwable) {
            // Cache unavailable — verify the slow way below.
        }

        if (! Hash::check($providedKey, $site->api_key)) {
            return false;
        }

        try {
            Cache::put($cacheKey, true, self::VERIFIED_TTL_SECONDS);
        } catch (Throwable) {
            // Not remembered; the next request simply checks again.
        }

        return true;
    }

    /**
     * Where a verified (site, key) pair is remembered.
     *
     * Keyed on an HMAC of the provided key AND the site's CURRENT stored hash:
     *
     *  - rotating the key replaces the stored hash, so the old key maps to a
     *    different entry and is rejected on the very next request — no
     *    invalidation code, nothing to forget to call;
     *  - the plain key never reaches the cache store, and the entry name is
     *    useless without APP_KEY;
     *  - the site id scopes it, so one site's key can never verify another's.
     */
    private function verifiedKeyCacheKey(Site $site, string $providedKey): string
    {
        return 'site-key-ok:'.$site->id.':'.hash_hmac(
            'sha256',
            $providedKey.'|'.$site->api_key,
            (string) config('app.key'),
        );
    }
}
