<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

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
        if (self::taggable()) {
            return Cache::tags([self::siteTag($siteId), ...$tags])->remember($key, $ttl, $callback);
        }

        return Cache::remember($key . ':v' . self::version($siteId), $ttl, $callback);
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
