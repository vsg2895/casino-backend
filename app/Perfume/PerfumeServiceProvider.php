<?php

declare(strict_types=1);

namespace App\Perfume;

use App\Perfume\Http\Middleware\VerifyPerfumeKey;
use App\Perfume\Models\Brand;
use App\Perfume\Models\Category;
use App\Perfume\Models\HomeCard;
use App\Perfume\Models\NavItem;
use App\Perfume\Models\Page;
use App\Perfume\Models\Perfumer;
use App\Perfume\Models\Product;
use App\Perfume\Models\ProductImage;
use App\Perfume\Models\ProductNote;
use App\Perfume\Models\ProductVariant;
use App\Perfume\Models\ScentFamily;
use App\Perfume\Models\ScentNote;
use App\Perfume\Models\Setting;
use App\Perfume\Models\Store;
use App\Perfume\Observers\PerfumeCacheObserver;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the perfume store adds to the application, registered from ONE
 * place so the casino platform's own bootstrap files stay untouched: the
 * route file and its prefix, the `verify.perfume` middleware alias, the
 * migrations folder and the cache observers.
 *
 * Listed in bootstrap/providers.php — the only existing file this feature
 * edits on the backend.
 */
class PerfumeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/perfume'));

        Route::aliasMiddleware('verify.perfume', VerifyPerfumeKey::class);

        /*
         * `name('perfume.')` is not cosmetic — it is what lets the app cache
         * its routes at all.
         *
         * `apiResource('categories', …)` names its routes `categories.index`,
         * `categories.store` and so on, and the casino admin already owns those
         * names under /api/v1/admin. Two routes with one name are tolerated in
         * dev, where nothing caches and the last registration simply wins; they
         * are FATAL to `route:cache`, which refuses to serialize the second one
         * ("Another route has already been assigned name [categories.index]").
         * A container that caches routes on boot therefore failed to start,
         * while local development never noticed.
         *
         * Prefixing the names here rather than in routes/perfume.php covers
         * every resource in that file and every one added later. Only the NAMES
         * change — the URLs, the middleware and the controllers are untouched.
         */
        Route::middleware('api')
            ->prefix('api/perfume')
            ->name('perfume.')
            ->group(base_path('routes/perfume.php'));

        // Any saved/deleted row invalidates the public cache and asks the
        // storefront to regenerate; the observer is the same for every model.
        foreach ([
            Setting::class, Brand::class, Category::class, ScentNote::class, ScentFamily::class,
            Perfumer::class, Product::class, ProductVariant::class, ProductImage::class,
            ProductNote::class, HomeCard::class, NavItem::class, Store::class, Page::class,
        ] as $model) {
            $model::observe(PerfumeCacheObserver::class);
        }
    }
}
