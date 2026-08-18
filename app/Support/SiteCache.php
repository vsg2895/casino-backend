<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use JsonSerializable;

/**
 * Per-site cache helper that works on ANY cache store.
 *
 * When the configured store supports tags (redis/memcached/array) it uses
 * tag-based invalidation. On non-taggable stores (file/database) it falls back
 * to a per-site version key: bumping the version makes every cached key for that
 * site unreachable, giving the same "flush a whole site" behaviour without tags.
 */
final class SiteCache
{
    /**
     * @param string[] $tags Extra tags (used only when the store is taggable).
     */
    public static function remember(int $siteId, array $tags, string $key, int $ttl, Closure $callback): mixed
    {
        // Whatever the callback produces is normalised to plain arrays/scalars
        // BEFORE it reaches the store — see normalise().
        $wrapped = static fn (): mixed => self::normalise($callback());

        if (self::taggable()) {
            return Cache::tags([self::siteTag($siteId), ...$tags])->remember($key, $ttl, $wrapped);
        }

        return Cache::remember($key . ':v' . self::version($siteId), $ttl, $wrapped);
    }

    /**
     * Reduce a value to plain arrays and scalars so it survives the cache.
     *
     * This is not tidiness, it is a correctness fix. Callers hand us
     * `SomeResource::collection($models)->resolve()`, and `resolve()` is SHALLOW:
     * nested `CategoryResource::collection(...)` entries stay as live Resource
     * objects, and dates stay as Carbon instances. Those get PHP-serialized into
     * the store and come back as `__PHP_Incomplete_Class`, so a cache HIT returned
     * a structurally different payload from a cache MISS — the casino endpoint
     * dropped from 186 leaf fields to 32, losing categories and special offers
     * entirely. It only ever looked fine because the FIRST request (a miss) is
     * JSON-encoded on the way out, which resolves everything.
     *
     * Round-tripping through JSON is what the HTTP response does anyway, so the
     * cached value is now byte-identical to the uncached one by construction.
     */
    private static function normalise(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value) || $value instanceof JsonSerializable) {
            /** @var mixed $decoded */
            $decoded = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        }

        return $value;
    }

    /** Invalidate every cached entry belonging to a site. */
    public static function flushSite(int $siteId): void
    {
        if (self::taggable()) {
            Cache::tags([self::siteTag($siteId)])->flush();

            return;
        }

        Cache::forever(self::versionKey($siteId), self::version($siteId) + 1);
    }

    private static function taggable(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    private static function siteTag(int $siteId): string
    {
        return 'site:' . $siteId;
    }

    private static function version(int $siteId): int
    {
        return (int) Cache::get(self::versionKey($siteId), 1);
    }

    private static function versionKey(int $siteId): string
    {
        return 'site:' . $siteId . ':cachever';
    }
}
