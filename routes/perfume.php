<?php

declare(strict_types=1);

use App\Perfume\Http\Controllers\Admin;
use App\Perfume\Http\Controllers\Public\OrderController as PublicOrderController;
use App\Perfume\Http\Controllers\Public\StorefrontController;
use App\Perfume\Http\Controllers\Public\SubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Big Glow Day — perfume store routes
|--------------------------------------------------------------------------
|
| Mounted at /api/perfume by App\Perfume\PerfumeServiceProvider. Two groups:
|
|   /api/perfume/public/*   the storefront, gated by X-Perfume-Key
|   /api/perfume/admin/*    the admin section, gated by auth:sanctum
|
| Nothing here is reachable through the casino sites' /api/v1 prefixes.
|
*/

Route::prefix('public')->middleware('verify.perfume')->group(function (): void {
    Route::get('settings', [StorefrontController::class, 'settings']);
    Route::get('nav', [StorefrontController::class, 'nav']);
    Route::get('home', [StorefrontController::class, 'home']);
    Route::get('sitemap', [StorefrontController::class, 'sitemap']);

    Route::get('categories', [StorefrontController::class, 'categories']);
    Route::get('categories/{slug}', [StorefrontController::class, 'category']);
    Route::get('products', [StorefrontController::class, 'products']);
    Route::get('products/{slug}', [StorefrontController::class, 'product']);
    Route::get('brands', [StorefrontController::class, 'brands']);
    Route::get('brands/{slug}', [StorefrontController::class, 'brand']);
    Route::get('notes', [StorefrontController::class, 'notes']);
    Route::get('notes/{slug}', [StorefrontController::class, 'note']);
    Route::get('perfumers', [StorefrontController::class, 'perfumers']);
    Route::get('perfumers/{slug}', [StorefrontController::class, 'perfumer']);
    Route::get('pages', [StorefrontController::class, 'pages']);
    Route::get('pages/{slug}', [StorefrontController::class, 'page']);

    /*
     * Writes. Throttled per ip — everything above is a cached read, these
     * three put rows in the database, and the public group has no limit of
     * its own. Unsubscribe is POST-only on purpose: a GET opt-out link is
     * fetched by mail clients and would opt people out before they read it.
     */
    Route::post('orders', [PublicOrderController::class, 'store'])->middleware('throttle:10,1');
    Route::post('subscribe', [SubscriptionController::class, 'subscribe'])->middleware('throttle:6,1');
    Route::post('unsubscribe', [SubscriptionController::class, 'unsubscribe'])->middleware('throttle:30,1');
});

Route::prefix('admin')->middleware('auth:sanctum')->group(function (): void {
    Route::get('settings', [Admin\SettingController::class, 'show']);
    Route::put('settings', [Admin\SettingController::class, 'update']);

    Route::apiResource('brands', Admin\BrandController::class);
    Route::apiResource('categories', Admin\CategoryController::class);
    Route::apiResource('scent-families', Admin\ScentFamilyController::class)->parameters(['scent-families' => 'scentFamily']);
    Route::apiResource('scent-notes', Admin\ScentNoteController::class)->parameters(['scent-notes' => 'scentNote']);
    Route::apiResource('perfumers', Admin\PerfumerController::class);
    Route::apiResource('products', Admin\ProductController::class);
    Route::apiResource('home-cards', Admin\HomeCardController::class)->parameters(['home-cards' => 'homeCard']);
    Route::apiResource('nav-items', Admin\NavItemController::class)->parameters(['nav-items' => 'navItem']);
    Route::apiResource('stores', Admin\StoreController::class);
    Route::apiResource('pages', Admin\PageController::class);

    // The store's own desk: what was ordered, and who asked to hear from it.
    //
    // There is no campaign tooling here on purpose: the shop sends exactly
    // one email, the subscribe confirmation, and that is written in code.
    Route::apiResource('orders', Admin\OrderController::class)->only(['index', 'show', 'update', 'destroy']);
    Route::apiResource('subscribers', Admin\SubscriberController::class)->except(['show']);
});
