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

        Route::middleware('api')
            ->prefix('api/perfume')
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
