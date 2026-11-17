<?php

declare(strict_types=1);

namespace App\Perfume\Support;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;
use JsonSerializable;

/**
 * Cache for the store's public reads. Same two rules as the platform's
 * SiteCache, kept as its own copy so the store never depends on it:
 *
 *  1. Values are round-tripped through JSON before storage, so a cache hit
 *     is byte-identical to a miss (no Carbon / Resource objects serialised).
 *  2. One tag ("perfume") on taggable stores, a version key otherwise, so
 *     flush() empties everything the store has cached and nothing else.
 */
final class PerfumeCache
{
    private const string TAG = 'perfume';

    private const string VERSION_KEY = 'perfume:cache-version';

    public static function remember(string $key, Closure $callback): mixed
    {
        $ttl = (int) config('perfume.cache_ttl', 3600);
        $wrapped = static fn (): mixed => self::normalise($callback());

        if (self::taggable()) {
            return Cache::tags([self::TAG])->remember('perfume:' . $key, $ttl, $wrapped);
        }

        return Cache::remember('perfume:' . $key . ':v' . self::version(), $ttl, $wrapped);
    }

    public static function flush(): void
    {
        if (self::taggable()) {
            Cache::tags([self::TAG])->flush();

            return;
        }

        Cache::forever(self::VERSION_KEY, self::version() + 1);
    }

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

    private static function taggable(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    private static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }
}
