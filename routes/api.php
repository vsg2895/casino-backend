<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\CasinoController as AdminCasinoController;
use App\Http\Controllers\Api\Admin\CasinoSiteAttachmentController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CmsPageController as AdminCmsPageController;
use App\Http\Controllers\Api\Admin\EmailScheduleController;
use App\Http\Controllers\Api\Admin\MediaUploadController;
use App\Http\Controllers\Api\Admin\PromotionEmailHistoryController;
use App\Http\Controllers\Api\Admin\NewsletterController as AdminNewsletterController;
use App\Http\Controllers\Api\Admin\SiteController;
use App\Http\Controllers\Api\Admin\SiteEmailTemplateController;
use App\Http\Controllers\Api\Admin\SitePromotionEmailController;
use App\Http\Controllers\Api\Admin\SocialLinkController as AdminSocialLinkController;
use App\Http\Controllers\Api\Admin\SpecialOfferController as AdminSpecialOfferController;
use App\Http\Controllers\Api\Admin\UnsubscribeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Public\CasinoController as PublicCasinoController;
use App\Http\Controllers\Api\Public\CategoryController as PublicCategoryController;
use App\Http\Controllers\Api\Public\CmsPageController as PublicCmsPageController;
use App\Http\Controllers\Api\Public\NewsletterController as PublicNewsletterController;
use App\Http\Controllers\Api\Public\SocialLinkController as PublicSocialLinkController;
use App\Http\Controllers\Api\Public\SpecialOfferController as PublicSpecialOfferController;
use App\Http\Controllers\Api\Public\UnsubscribeController as PublicUnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // ── Admin auth (public — no token required) ─────────────────────────
    Route::prefix('admin/auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);
    });

    // ── Admin (protected) ────────────────────────────────────────────────
    Route::prefix('admin')->middleware('auth:sanctum')->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });

        // Media uploads (drag & drop images)
        Route::post('uploads', [MediaUploadController::class, 'store']);

        // Sites
        Route::apiResource('sites', SiteController::class);
        Route::post('sites/{site}/rotate-key', [SiteController::class, 'rotateKey']);

        // Per-site subscription email template
        Route::get('sites/{site}/email-template', [SiteEmailTemplateController::class, 'show']);
        Route::put('sites/{site}/email-template', [SiteEmailTemplateController::class, 'update']);
        Route::post('sites/{site}/email-template/preview', [SiteEmailTemplateController::class, 'preview']);
        Route::post('sites/{site}/email-template/test', [SiteEmailTemplateController::class, 'sendTest']);

        // Per-site promotion (marketing offer) email template
        Route::get('sites/{site}/promotion-email', [SitePromotionEmailController::class, 'show']);
        Route::put('sites/{site}/promotion-email', [SitePromotionEmailController::class, 'update']);
        Route::post('sites/{site}/promotion-email/preview', [SitePromotionEmailController::class, 'preview']);
        Route::post('sites/{site}/promotion-email/test', [SitePromotionEmailController::class, 'sendTest']);

        // Casinos ("Products")
        Route::apiResource('casinos', AdminCasinoController::class);
        Route::prefix('casinos/{casino}/sites')->group(function () {
            Route::get('',          [CasinoSiteAttachmentController::class, 'index']);
            Route::post('sync',     [CasinoSiteAttachmentController::class, 'sync']);
            Route::post('',         [CasinoSiteAttachmentController::class, 'store']);
            Route::patch('{site}',  [CasinoSiteAttachmentController::class, 'update']);
            Route::delete('{site}', [CasinoSiteAttachmentController::class, 'destroy']);
        });

        // Categories
        Route::apiResource('categories', AdminCategoryController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Special Offers
        Route::apiResource('special-offers', AdminSpecialOfferController::class);
        Route::post('special-offers/{specialOffer}/duplicate', [AdminSpecialOfferController::class, 'duplicate']);

        // Newsletter
        Route::get('newsletters', [AdminNewsletterController::class, 'index']);
        Route::post('newsletters', [AdminNewsletterController::class, 'store']);
        Route::post('newsletters/import', [AdminNewsletterController::class, 'import']);
        Route::get('newsletters/export', [AdminNewsletterController::class, 'export']);
        Route::post('newsletters/bulk-delete', [AdminNewsletterController::class, 'bulkDestroy']);
        Route::post('newsletters/delete-all', [AdminNewsletterController::class, 'destroyAll']);
        Route::post('newsletters/restore', [AdminNewsletterController::class, 'bulkRestore']);
        Route::post('newsletters/force-delete', [AdminNewsletterController::class, 'bulkForceDestroy']);
        Route::delete('newsletters/{newsletter}', [AdminNewsletterController::class, 'destroy']);
        Route::post('newsletters/{newsletter}/restore', [AdminNewsletterController::class, 'restore'])->withTrashed();
        Route::delete('newsletters/{newsletter}/force', [AdminNewsletterController::class, 'forceDestroy'])->withTrashed();

        // Scheduled promotion campaigns
        Route::apiResource('schedules', EmailScheduleController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::post('schedules/{schedule}/run', [EmailScheduleController::class, 'run']);

        // Promotion delivery history (read-only; partitioned + prefix search)
        Route::get('promotion-history', [PromotionEmailHistoryController::class, 'index']);

        // Unsubscribes (per-stream opt-out log)
        Route::get('unsubscribes', [UnsubscribeController::class, 'index']);
        Route::get('unsubscribes/export', [UnsubscribeController::class, 'export']);
        Route::delete('unsubscribes/{unsubscribe}', [UnsubscribeController::class, 'destroy']);

        // Social media links (per-site)
        Route::apiResource('social-links', AdminSocialLinkController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // CMS / Legal pages (per-site content — authorized via CmsPagePolicy)
        Route::apiResource('pages', AdminCmsPageController::class);
    });

    // ── One-click unsubscribe (RFC 8058) — keyless, token is the credential ──
    // Target of the List-Unsubscribe-Post header. POST-only (GET links get
    // prefetched → accidental unsubscribes). Not behind verify.site: providers
    // send neither the site key nor the slug.
    Route::post('unsubscribe/{token}', [PublicUnsubscribeController::class, 'oneClick'])
        ->middleware('throttle:60,1');

    // ── Public (site-keyed) ──────────────────────────────────────────────
    Route::prefix('public/sites/{site}')->middleware('verify.site')->group(function () {
        Route::get('casinos',                 [PublicCasinoController::class, 'index']);
        Route::get('casinos/{slug}',          [PublicCasinoController::class, 'show']);

        Route::get('categories',              [PublicCategoryController::class, 'index']);
        Route::get('categories/{slug}',       [PublicCategoryController::class, 'show']);

        Route::get('special-offers',          [PublicSpecialOfferController::class, 'index']);
        Route::get('special-offers/{slug}',   [PublicSpecialOfferController::class, 'show']);

        Route::get('social-links',            [PublicSocialLinkController::class, 'index']);

        // CMS / Legal pages (published only)
        Route::get('pages/{slug}',            [PublicCmsPageController::class, 'show']);

        // Newsletter signup + one-click unsubscribe (token-based)
        Route::post('newsletter', [PublicNewsletterController::class, 'store']);
        Route::post('newsletter/unsubscribe', [PublicNewsletterController::class, 'unsubscribe']);
    });
});
