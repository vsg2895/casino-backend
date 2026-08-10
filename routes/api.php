<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\CasinoController as AdminCasinoController;
use App\Http\Controllers\Api\Admin\CasinoSiteAttachmentController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CmsPageController as AdminCmsPageController;
use App\Http\Controllers\Api\Admin\EmailScheduleController;
use App\Http\Controllers\Api\Admin\MediaUploadController;
use App\Http\Controllers\Api\Admin\MailgunKeyController;
use App\Http\Controllers\Api\Admin\PromotionEmailHistoryController;
use App\Http\Controllers\Api\Admin\NewsletterController as AdminNewsletterController;
use App\Http\Controllers\Api\Admin\NewsletterPhoneController;
use App\Http\Controllers\Api\Admin\SmsTemplateController;
use App\Http\Controllers\Api\Admin\TwilioConfigController;
use App\Http\Controllers\Api\Admin\EmailTemplateTypeController;
use App\Http\Controllers\Api\Admin\SendgridKeyController;
use App\Http\Controllers\Api\Admin\SiteController;
use App\Http\Controllers\Api\Admin\SiteEmailTemplateController;
use App\Http\Controllers\Api\Admin\SiteVerifyEmailController;
use App\Http\Controllers\Api\Admin\SitePromotionEmailController;
use App\Http\Controllers\Api\Admin\SocialLinkController as AdminSocialLinkController;
use App\Http\Controllers\Api\Admin\SpecialOfferController as AdminSpecialOfferController;
use App\Http\Controllers\Api\Admin\UnsubscribeController;
use App\Http\Controllers\Api\Admin\WarmupEmailController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Public\CasinoController as PublicCasinoController;
use App\Http\Controllers\Api\Public\CategoryController as PublicCategoryController;
use App\Http\Controllers\Api\Public\CmsPageController as PublicCmsPageController;
use App\Http\Controllers\Api\Public\NewsletterController as PublicNewsletterController;
use App\Http\Controllers\Api\Public\SocialLinkController as PublicSocialLinkController;
use App\Http\Controllers\Api\Public\SpecialOfferController as PublicSpecialOfferController;
use App\Http\Controllers\Api\Public\UnsubscribeController as PublicUnsubscribeController;
use App\Http\Controllers\Api\Public\VerifyController as PublicVerifyController;
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

        // Per-site "verify your email" template
        Route::get('sites/{site}/verify-email', [SiteVerifyEmailController::class, 'show']);
        Route::put('sites/{site}/verify-email', [SiteVerifyEmailController::class, 'update']);
        Route::post('sites/{site}/verify-email/preview', [SiteVerifyEmailController::class, 'preview']);
        Route::post('sites/{site}/verify-email/test', [SiteVerifyEmailController::class, 'sendTest']);

        // Per-site promotion (marketing offer) email template
        Route::get('sites/{site}/promotion-email', [SitePromotionEmailController::class, 'show']);
        Route::put('sites/{site}/promotion-email', [SitePromotionEmailController::class, 'update']);
        Route::post('sites/{site}/promotion-email/preview', [SitePromotionEmailController::class, 'preview']);
        Route::post('sites/{site}/promotion-email/test', [SitePromotionEmailController::class, 'sendTest']);

        // Casinos ("Products")
        // Dedicated record counter. MUST be declared before the resource route,
        // or `casinos/{casino}` would swallow "count" as an id.
        Route::get('casinos/count', [AdminCasinoController::class, 'count']);
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
        // Dedicated record counter. MUST be declared before the resource route,
        // or `special-offers/{specialOffer}` would swallow "count" as an id.
        Route::get('special-offers/count', [AdminSpecialOfferController::class, 'count']);
        Route::apiResource('special-offers', AdminSpecialOfferController::class);
        Route::post('special-offers/{specialOffer}/duplicate', [AdminSpecialOfferController::class, 'duplicate']);

        // Newsletter
        Route::get('newsletters', [AdminNewsletterController::class, 'index']);
        Route::get('newsletters/count', [AdminNewsletterController::class, 'count']);
        Route::post('newsletters', [AdminNewsletterController::class, 'store']);
        Route::post('newsletters/import', [AdminNewsletterController::class, 'import']);
        // Progress of a queued import — polled by the admin panel until finished.
        Route::get('newsletters/imports/{import}', [AdminNewsletterController::class, 'importStatus']);
        Route::get('newsletters/export', [AdminNewsletterController::class, 'export']);
        Route::post('newsletters/bulk-delete', [AdminNewsletterController::class, 'bulkDestroy']);
        Route::post('newsletters/delete-all', [AdminNewsletterController::class, 'destroyAll']);
        Route::post('newsletters/restore', [AdminNewsletterController::class, 'bulkRestore']);
        Route::post('newsletters/force-delete', [AdminNewsletterController::class, 'bulkForceDestroy']);
        Route::delete('newsletters/{newsletter}', [AdminNewsletterController::class, 'destroy']);
        Route::post('newsletters/{newsletter}/restore', [AdminNewsletterController::class, 'restore'])->withTrashed();
        Route::delete('newsletters/{newsletter}/force', [AdminNewsletterController::class, 'forceDestroy'])->withTrashed();

        // ── Newsletters based on phone (STANDALONE) ─────────────────────
        // Backed by `newsletters_based_on_phone` and nothing else: no site
        // scoping, no client relationship, and no overlap with the email
        // newsletter routes above. Literal segments are declared BEFORE the
        // apiResource, or `{newsletter_phone}` swallows "count", "import" and
        // "send" as ids.
        Route::get('newsletter-phones/count', [NewsletterPhoneController::class, 'count']);
        Route::get('newsletter-phones/export', [NewsletterPhoneController::class, 'export']);
        Route::post('newsletter-phones/import', [NewsletterPhoneController::class, 'import']);
        // Progress of a queued import — polled by the admin panel until finished.
        Route::get('newsletter-phones/imports/{import}', [NewsletterPhoneController::class, 'importStatus']);
        Route::post('newsletter-phones/bulk-delete', [NewsletterPhoneController::class, 'bulkDestroy']);
        Route::post('newsletter-phones/delete-all', [NewsletterPhoneController::class, 'destroyAll']);
        // Who a send with the current filters would reach (same query as the
        // send itself), and the same list as a CSV.
        Route::get('newsletter-phones/recipients', [NewsletterPhoneController::class, 'recipients']);
        Route::get('newsletter-phones/recipients/export', [NewsletterPhoneController::class, 'exportRecipients']);
        // Start a bulk SMS run. Guarded by a cross-process lock in the
        // controller — two concurrent runs would double-charge.
        Route::post('newsletter-phones/send', [NewsletterPhoneController::class, 'send']);
        // Per-recipient outcome of past runs.
        Route::get('newsletter-phones/history', [NewsletterPhoneController::class, 'history']);
        Route::get('newsletter-phones/history/count', [NewsletterPhoneController::class, 'historyCount']);
        Route::apiResource('newsletter-phones', NewsletterPhoneController::class)
            ->except(['show']);

        // Reusable SMS message texts. Editing one changes what the NEXT send
        // starts from; runs already queued carry their own copy of the body.
        Route::apiResource('sms-templates', SmsTemplateController::class)
            ->except(['show']);
        Route::patch('sms-templates/{sms_template}/toggle', [SmsTemplateController::class, 'toggle']);

        // Twilio credentials — same CRUD / toggle / test contract as the
        // sendgrid-keys and mailgun-keys resources below, so the admin panel
        // reuses one workflow for every provider.
        Route::apiResource('twilio-configs', TwilioConfigController::class)
            ->except(['show']);
        Route::patch('twilio-configs/{twilio_config}/toggle', [TwilioConfigController::class, 'toggle']);
        // Verify a stored credential actually authenticates + delivers, by
        // sending one real message through it.
        Route::post('twilio-configs/{twilio_config}/test', [TwilioConfigController::class, 'test']);

        // Email warmup list — addresses used to build the sending mailbox's
        // reputation. Counter route BEFORE the resource, or `{warmup_email}`
        // would swallow "count" as an id.
        Route::get('warmup-emails/count', [WarmupEmailController::class, 'count']);
        Route::post('warmup-emails/import', [WarmupEmailController::class, 'import']);
        Route::post('warmup-emails/bulk-delete', [WarmupEmailController::class, 'bulkDestroy']);
        Route::post('warmup-emails/send', [WarmupEmailController::class, 'send']);
        Route::apiResource('warmup-emails', WarmupEmailController::class)
            ->except(['show']);

        // Scheduled promotion campaigns
        // SendGrid API keys — alternative transport for scheduled promotions.
        Route::apiResource('sendgrid-keys', SendgridKeyController::class)
            ->except(['show']);
        Route::patch('sendgrid-keys/{sendgrid_key}/toggle', [SendgridKeyController::class, 'toggle']);
        // Verify a stored key actually authenticates + delivers, by sending a
        // real site template through it.
        Route::post('sendgrid-keys/{sendgrid_key}/test', [SendgridKeyController::class, 'test']);
        // Mailgun credentials — same contract as sendgrid-keys above, so the
        // admin panel reuses the identical CRUD / toggle / test workflow.
        Route::apiResource('mailgun-keys', MailgunKeyController::class)
            ->except(['show']);
        Route::patch('mailgun-keys/{mailgun_key}/toggle', [MailgunKeyController::class, 'toggle']);
        Route::post('mailgun-keys/{mailgun_key}/test', [MailgunKeyController::class, 'test']);
        // Templates available to that test (drives the admin dropdown).
        Route::get('email-template-types', [EmailTemplateTypeController::class, 'index']);

        Route::apiResource('schedules', EmailScheduleController::class)
            ->only(['index', 'store', 'update', 'destroy']);
        Route::post('schedules/{schedule}/run', [EmailScheduleController::class, 'run']);
        // Who would receive this campaign right now (same query as the send).
        Route::get('schedules/{schedule}/recipients', [EmailScheduleController::class, 'recipients']);
        Route::get('schedules/{schedule}/recipients/export', [EmailScheduleController::class, 'exportRecipients']);

        // Promotion delivery history (read-only; partitioned + prefix search)
        Route::get('promotion-history', [PromotionEmailHistoryController::class, 'index']);
        Route::get('promotion-history/count', [PromotionEmailHistoryController::class, 'count']);

        // Unsubscribes (per-stream opt-out log)
        Route::get('unsubscribes', [UnsubscribeController::class, 'index']);
        Route::get('unsubscribes/count', [UnsubscribeController::class, 'count']);
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

    // ── Double opt-in verify (keyless, token is the credential) ──────────────
    // Target of the verify link in the verify email. POST-only for parity with
    // one-click unsubscribe (GET links get prefetched). Not behind verify.site:
    // the opaque subscription token resolves the subscriber across all sites.
    Route::post('verify/{token}', [PublicVerifyController::class, 'verify'])
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
