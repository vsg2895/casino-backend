<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\CasinoController as AdminCasinoController;
use App\Http\Controllers\Api\Admin\CasinoReviewController as AdminCasinoReviewController;
use App\Http\Controllers\Api\Admin\ArticleController as AdminArticleController;
use App\Http\Controllers\Api\Admin\CasinoDetailController;
use App\Http\Controllers\Api\Admin\CasinoSiteAttachmentController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CasinoCountryController;
use App\Http\Controllers\Api\Admin\CountryController as AdminCountryController;
use App\Http\Controllers\Api\Admin\CmsPageController as AdminCmsPageController;
use App\Http\Controllers\Api\Admin\EmailScheduleController;
use App\Http\Controllers\Api\Admin\MediaUploadController;
use App\Http\Controllers\Api\Admin\NavItemController;
use App\Http\Controllers\Api\Admin\ForumArticleController;
use App\Http\Controllers\Api\Admin\ForumModerationController;
use App\Http\Controllers\Api\Admin\ForumTaxonomyController;
use App\Http\Controllers\Api\Admin\SiteForumController;
use App\Http\Controllers\Api\Admin\MailgunKeyController;
use App\Http\Controllers\Api\Admin\MailgunReceiverController;
use App\Http\Controllers\Api\Admin\SmtpCredentialController;
use App\Http\Controllers\Api\Admin\PromotionEmailHistoryController;
use App\Http\Controllers\Api\Admin\NewsletterController as AdminNewsletterController;
use App\Http\Controllers\Api\Admin\NewsletterPhoneController;
use App\Http\Controllers\Api\Admin\SmsTemplateController;
use App\Http\Controllers\Api\Admin\TwilioConfigController;
use App\Http\Controllers\Api\Admin\EmailTemplateTypeController;
use App\Http\Controllers\Api\Admin\RedirectController as AdminRedirectController;
use App\Http\Controllers\Api\Admin\SendgridKeyController;
use App\Http\Controllers\Api\Admin\SeoTemplateController;
use App\Http\Controllers\Api\Admin\SiteController;
use App\Http\Controllers\Api\Admin\SiteRevalidationController;
use App\Http\Controllers\Api\Admin\SiteEmailTemplateController;
use App\Http\Controllers\Api\Admin\SiteVerifyEmailController;
use App\Http\Controllers\Api\Admin\SitePromotionEmailController;
use App\Http\Controllers\Api\Admin\VerificationPromotionEmailController;
use App\Http\Controllers\Api\Admin\SocialLinkController as AdminSocialLinkController;
use App\Http\Controllers\Api\Admin\SpecialOfferController as AdminSpecialOfferController;
use App\Http\Controllers\Api\Admin\EmailValidationCheckController;
use App\Http\Controllers\Api\Admin\EmailValidationLogController;
use App\Http\Controllers\Api\Admin\EmailValidationStatsController;
use App\Http\Controllers\Api\Admin\UnsubscribeController;
use App\Http\Controllers\Api\Admin\WarmupEmailController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Public\CasinoController as PublicCasinoController;
use App\Http\Controllers\Api\Public\ForumAuthController;
use App\Http\Controllers\Api\Public\ForumController as PublicForumController;
use App\Http\Controllers\Api\Public\ForumPostController;
use App\Http\Controllers\Api\Public\CasinoReviewController as PublicCasinoReviewController;
use App\Http\Controllers\Api\Public\CategoryController as PublicCategoryController;
use App\Http\Controllers\Api\Public\CountryController as PublicCountryController;
use App\Http\Controllers\Api\Admin\BonusCategoryController as AdminBonusCategoryController;
use App\Http\Controllers\Api\Admin\NewsCategoryController as AdminNewsCategoryController;
use App\Http\Controllers\Api\Public\BonusController as PublicBonusController;
use App\Http\Controllers\Api\Public\NewsController as PublicNewsController;
use App\Http\Controllers\Api\Public\CmsPageController as PublicCmsPageController;
use App\Http\Controllers\Api\Public\AffiliateClickController;
use App\Http\Controllers\Api\Public\ArticleController as PublicArticleController;
use App\Http\Controllers\Api\Public\EditorialController;
use App\Http\Controllers\Api\Public\MailgunUnsubscribeController;
use App\Http\Controllers\Api\Public\MailgunWebhookController;
use App\Http\Controllers\Api\Public\NavigationController as PublicNavigationController;
use App\Http\Controllers\Api\Public\RedirectController as PublicRedirectController;
use App\Http\Controllers\Api\Public\NewsletterController as PublicNewsletterController;
use App\Http\Controllers\Api\Public\SiteFeatureController;
use App\Http\Controllers\Api\Public\SocialLinkController as PublicSocialLinkController;
use App\Http\Controllers\Api\Public\SearchController as PublicSearchController;
use App\Http\Controllers\Api\Public\SpecialOfferController as PublicSpecialOfferController;
use App\Http\Controllers\Api\Public\UnsubscribeController as PublicUnsubscribeController;
use App\Http\Controllers\Api\Public\VerifyController as PublicVerifyController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UniOne\UniOneKeyController;
use App\Http\Controllers\Api\UniOne\UniOneReceiverController;
use App\Http\Controllers\Api\UniOne\UniOneSendController;
use App\Http\Controllers\Api\UniOne\UniOneWebhookController;

Route::prefix('v1')->group(function () {

    // ── Admin auth (public — no token required) ─────────────────────────
    Route::prefix('admin/auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        // Password reset. Public by necessity — someone who cannot sign in
        // cannot carry a token — so the emailed token IS the credential, and
        // both are throttled hard for it. The broker adds its own per-address
        // cooldown on top (config/auth.php 'throttle'); this limit is what stops
        // an attacker cycling through ADDRESSES rather than retrying one.
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
            ->middleware('throttle:6,1');
        Route::post('reset-password', [AuthController::class, 'resetPassword'])
            ->middleware('throttle:6,1');
    });

    // ── Admin (protected) ────────────────────────────────────────────────
    Route::prefix('admin')->middleware('auth:sanctum')->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            // Ends every session except this one. Signing in does the same
            // implicitly; this is the control for an admin who is already
            // signed in and does not want to sign themselves out to use it.
            Route::post('logout-other-devices', [AuthController::class, 'logoutOtherDevices']);
            Route::get('me', [AuthController::class, 'me']);

            // Throttled despite already requiring a token: the endpoint takes
            // the CURRENT password, so without a limit a stolen session becomes
            // an offline-speed oracle for guessing it. Same budget as the reset
            // routes above, for the same reason.
            Route::post('change-password', [AuthController::class, 'changePassword'])
                ->middleware('throttle:6,1');
        });

        // Media uploads (drag & drop images)
        Route::post('uploads', [MediaUploadController::class, 'store']);

        // Sites
        Route::apiResource('sites', SiteController::class);
        Route::post('sites/{site}/rotate-key', [SiteController::class, 'rotateKey']);

        // Cache health. "revalidate" and "revalidations" are literal segments
        // under {site}, which is a model binding — no ordering hazard.
        Route::get('sites/{site}/revalidations', [SiteRevalidationController::class, 'index']);
        Route::post('sites/{site}/revalidate', [SiteRevalidationController::class, 'store']);

        // Navigation — per site, because a menu belongs to exactly one domain.
        // "reorder" is declared BEFORE the {nav_item} routes, or it is swallowed
        // as an id, which is the ordering rule this file follows throughout.
        // The forum page's rules for one site. GET creates the row with
        // defaults on first access, so the screen always has something to show.
        Route::get('sites/{site}/forum', [SiteForumController::class, 'show']);
        Route::put('sites/{site}/forum', [SiteForumController::class, 'update']);

        Route::get('sites/{site}/nav-items', [NavItemController::class, 'index']);
        Route::post('sites/{site}/nav-items/reorder', [NavItemController::class, 'reorder']);
        Route::post('sites/{site}/nav-items', [NavItemController::class, 'store']);
        Route::put('sites/{site}/nav-items/{navItem}', [NavItemController::class, 'update']);
        Route::delete('sites/{site}/nav-items/{navItem}', [NavItemController::class, 'destroy']);

        // Redirects — per site, because the six domains have different URL
        // histories. {redirect} is a model binding; no literal segments share
        // this prefix, so no ordering hazard here.
        Route::get('sites/{site}/redirects', [AdminRedirectController::class, 'index']);
        Route::post('sites/{site}/redirects', [AdminRedirectController::class, 'store']);
        Route::put('sites/{site}/redirects/{redirect}', [AdminRedirectController::class, 'update']);
        Route::delete('sites/{site}/redirects/{redirect}', [AdminRedirectController::class, 'destroy']);

        // Guides — the only content type that belongs to a single site, so it
        // is nested under one rather than living at the top level.
        // News categories — one site's editorial sections.
        Route::get('sites/{site}/news-categories', [AdminNewsCategoryController::class, 'index']);
        Route::post('sites/{site}/news-categories', [AdminNewsCategoryController::class, 'store']);
        Route::put('sites/{site}/news-categories/{newsCategory}', [AdminNewsCategoryController::class, 'update']);
        Route::delete('sites/{site}/news-categories/{newsCategory}', [AdminNewsCategoryController::class, 'destroy']);

        Route::get('sites/{site}/articles', [AdminArticleController::class, 'index']);
        Route::post('sites/{site}/articles', [AdminArticleController::class, 'store']);
        Route::get('sites/{site}/articles/{article}', [AdminArticleController::class, 'show']);
        Route::put('sites/{site}/articles/{article}', [AdminArticleController::class, 'update']);
        Route::delete('sites/{site}/articles/{article}', [AdminArticleController::class, 'destroy']);

        // Per-site SEO patterns. Read and written as one set — see the
        // controller for why a per-entity endpoint would be worse.
        Route::get('sites/{site}/seo-templates', [SeoTemplateController::class, 'index']);
        Route::put('sites/{site}/seo-templates', [SeoTemplateController::class, 'update']);
        Route::post('sites/{site}/seo-templates/preview', [SeoTemplateController::class, 'preview']);

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

        // GLOBAL post-verification promotion — one template for every site, so
        // deliberately no {site} segment. Sent by SendVerificationPromotionJob
        // once `newsletters.created_at + delay_minutes` has elapsed for a
        // verified subscriber.
        Route::get('verification-promotion', [VerificationPromotionEmailController::class, 'show']);
        Route::put('verification-promotion', [VerificationPromotionEmailController::class, 'update']);
        Route::post('verification-promotion/preview', [VerificationPromotionEmailController::class, 'preview']);
        Route::post('verification-promotion/test', [VerificationPromotionEmailController::class, 'sendTest']);

        // Casinos ("Products")
        // Dedicated record counter. MUST be declared before the resource route,
        // or `casinos/{casino}` would swallow "count" as an id.
        Route::get('casinos/count', [AdminCasinoController::class, 'count']);
        Route::apiResource('casinos', AdminCasinoController::class);
        // The casino's factual profile, on its own endpoints so the existing
        // casino CRUD is untouched. Declared right after the resource because it
        // is scoped BY a casino rather than sharing its prefix ambiguously.
        // Literal segments BEFORE the {casino} routes below, or "profiles"
        // is swallowed as a casino id — the ordering rule this file follows.
        Route::get('casino-profiles/export', [CasinoDetailController::class, 'export']);
        Route::post('casino-profiles/import', [CasinoDetailController::class, 'import']);
        Route::get('casinos/{casino}/details', [CasinoDetailController::class, 'show']);
        Route::put('casinos/{casino}/details', [CasinoDetailController::class, 'update']);
        Route::prefix('casinos/{casino}/sites')->group(function () {
            Route::get('',          [CasinoSiteAttachmentController::class, 'index']);
            Route::post('sync',     [CasinoSiteAttachmentController::class, 'sync']);
            Route::post('',         [CasinoSiteAttachmentController::class, 'store']);
            Route::patch('{site}',  [CasinoSiteAttachmentController::class, 'update']);
            Route::delete('{site}', [CasinoSiteAttachmentController::class, 'destroy']);
        });

        // Category logos. A literal path under `uploads`, declared with the
        // other media routes rather than under `categories`, because it uploads
        // a file and returns a path — it touches no category row.
        Route::post('uploads/category-logo', [MediaUploadController::class, 'storeCategoryLogo']);

        // Categories
        Route::apiResource('categories', AdminCategoryController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        // Whole-table casino/country operations. Their own prefix rather than
        // `casinos/...`: they act on every casino, not on a `{casino}`, and a
        // literal segment sharing that prefix is what the route-ordering rule
        // warns about.
        Route::post('casino-countries/attach-all', [CasinoCountryController::class, 'attachAll']);
        Route::post('casino-countries/detach-all', [CasinoCountryController::class, 'detachAll']);

        // Countries. The literal `continents` segment is declared BEFORE the
        // resource, or `countries/{country}` would swallow it as an id.
        // Bonus categories — the sub-items under the Bonus menu. Global, so
        // they sit at the top level rather than under a site.
        Route::apiResource('bonus-categories', AdminBonusCategoryController::class)
            ->parameters(['bonus-categories' => 'bonusCategory']);

        Route::get('countries/continents', [AdminCountryController::class, 'continents']);
        Route::apiResource('countries', AdminCountryController::class)
            ->only(['index', 'store', 'update', 'destroy']);

        /*
         * ── Community forum, admin side ────────────────────────────────────
         *
         * Two shapes, because the data has two shapes. The taxonomy and the
         * discussions belong to ONE site and nest under it, as the Forum
         * settings screen and News Categories already do. The moderation queue
         * is cross-cutting — a moderator works the whole network's backlog in
         * one list — so it sits at the root beside Reviews.
         *
         * Literal segments are declared BEFORE anything parameterised, per the
         * platform convention: `forum-posts/counts` would otherwise be read as
         * a post id.
         */
        Route::get('forum-posts/counts', [ForumModerationController::class, 'counts']);
        Route::post('forum-posts/act', [ForumModerationController::class, 'act']);
        Route::get('forum-posts', [ForumModerationController::class, 'index']);

        // Literal before parameterised, per the platform convention.
        Route::get('forum-members', [ForumModerationController::class, 'members']);
        Route::get('forum-members/{forumUser}/posts', [ForumModerationController::class, 'memberPosts']);
        Route::patch('forum-members/{forumUser}/role', [ForumModerationController::class, 'memberRole']);
        Route::patch('forum-members/{forumUser}', [ForumModerationController::class, 'member']);

        // Per-site taxonomy and discussions.
        Route::get('sites/{site}/forum-sections', [ForumTaxonomyController::class, 'sections']);
        Route::post('sites/{site}/forum-sections', [ForumTaxonomyController::class, 'storeSection']);
        Route::put('sites/{site}/forum-sections/{forumSection}', [ForumTaxonomyController::class, 'updateSection']);
        Route::delete('sites/{site}/forum-sections/{forumSection}', [ForumTaxonomyController::class, 'destroySection']);

        Route::post('sites/{site}/forum-categories', [ForumTaxonomyController::class, 'storeCategory']);
        Route::put('sites/{site}/forum-categories/{forumCategory}', [ForumTaxonomyController::class, 'updateCategory']);
        Route::delete('sites/{site}/forum-categories/{forumCategory}', [ForumTaxonomyController::class, 'destroyCategory']);

        Route::get('sites/{site}/forum-articles', [ForumArticleController::class, 'index']);
        Route::post('sites/{site}/forum-articles', [ForumArticleController::class, 'store']);
        Route::get('sites/{site}/forum-articles/{forumArticle}', [ForumArticleController::class, 'show']);
        Route::put('sites/{site}/forum-articles/{forumArticle}', [ForumArticleController::class, 'update']);
        Route::delete('sites/{site}/forum-articles/{forumArticle}', [ForumArticleController::class, 'destroy']);

        // Visitor reviews (moderation). Literal segments before any parameter
        // route, or `reviews/{casinoReview}` would swallow them as ids.
        Route::get('reviews/count', [AdminCasinoReviewController::class, 'count']);
        Route::get('reviews/pending-count', [AdminCasinoReviewController::class, 'pendingCount']);
        Route::post('reviews/bulk', [AdminCasinoReviewController::class, 'bulk']);
        Route::get('reviews', [AdminCasinoReviewController::class, 'index']);
        Route::patch('reviews/{casinoReview}/visibility', [AdminCasinoReviewController::class, 'setVisibility']);
        Route::delete('reviews/{casinoReview}', [AdminCasinoReviewController::class, 'destroy']);

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
        // Templates a warmup run may use (catalog minus verify) — drives the
        // send dialog's dropdown from the same allow-list the validator uses.
        Route::get('warmup-emails/templates', [WarmupEmailController::class, 'templates']);
        Route::post('warmup-emails/import', [WarmupEmailController::class, 'import']);
        Route::post('warmup-emails/bulk-delete', [WarmupEmailController::class, 'bulkDestroy']);
        // Who a run with the current settings would reach — the same query the
        // send itself uses, so the preview can never promise a different number.
        Route::get('warmup-emails/recipients', [WarmupEmailController::class, 'recipients']);
        // Per-address delivery history: address, site, template, timestamp.
        // Also what makes the cooldown auditable after the fact.
        Route::get('warmup-emails/history', [WarmupEmailController::class, 'history']);
        Route::get('warmup-emails/history/count', [WarmupEmailController::class, 'historyCount']);
        Route::post('warmup-emails/send', [WarmupEmailController::class, 'send']);
        // Stop a wedged or unwanted run: cancels queued batches and frees the run
        // lock, so a new run can start immediately.
        Route::post('warmup-emails/cancel', [WarmupEmailController::class, 'cancel']);
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

        // ── Mailgun receivers ────────────────────────────────────────────────
        // Literal segments declared BEFORE the apiResource, or {mailgun_receiver}
        // swallows "count", "import" and "bulk" as ids — the ordering rule the
        // existing resources in this file follow.
        Route::get('mailgun-receivers/count', [MailgunReceiverController::class, 'count']);
        Route::post('mailgun-receivers/import', [MailgunReceiverController::class, 'import']);
        Route::get('mailgun-receivers/imports/{mailgun_receiver_import}', [MailgunReceiverController::class, 'importStatus']);
        Route::post('mailgun-receivers/bulk', [MailgunReceiverController::class, 'bulk']);
        // Whole-list reset of "Last sent"/"Sent". Takes no id list by design —
        // the artisan twin is mailgun:reset-receiver-sends.
        Route::post('mailgun-receivers/reset-sends', [MailgunReceiverController::class, 'resetSends']);
        Route::apiResource('mailgun-receivers', MailgunReceiverController::class)
            ->parameters(['mailgun-receivers' => 'mailgun_receiver'])
            ->except(['show']);

        // Per-credential receiver targeting. Nested under the credential because
        // the credential IS the configuration — there is no separate schedule.
        Route::get('mailgun-keys/{mailgun_key}/receiver-settings', [MailgunKeyController::class, 'receiverSettings']);
        Route::put('mailgun-keys/{mailgun_key}/receiver-settings', [MailgunKeyController::class, 'updateReceiverSettings']);
        Route::get('mailgun-keys/{mailgun_key}/receiver-preview', [MailgunKeyController::class, 'previewReceiverBatch']);
        // Renders the message from UNSAVED fields, which is why it is a POST —
        // the draft template travels in the body, as the other template previews do.
        Route::post('mailgun-keys/{mailgun_key}/receiver-message-preview', [MailgunKeyController::class, 'previewReceiverMessage']);
        // Re-seeds the form from the source site's promotion template.
        Route::get('mailgun-keys/{mailgun_key}/receiver-template-source', [MailgunKeyController::class, 'receiverTemplateSource']);
        Route::post('mailgun-keys/{mailgun_key}/receiver-run', [MailgunKeyController::class, 'runReceiverCampaign']);

        // ── SMTP credentials (Email Configs) ─────────────────────────────────
        // Own SMTP servers that mail the SAME receiver list through a different
        // transport. Same CRUD / toggle / test / receiver-targeting contract as
        // mailgun-keys above, minus scheduling: this channel runs only from the
        // "Run now" button, so there is no send_enabled and no scheduler entry.
        Route::apiResource('smtp-credentials', SmtpCredentialController::class)
            ->parameters(['smtp-credentials' => 'smtp_credential'])
            ->except(['show']);
        Route::patch('smtp-credentials/{smtp_credential}/toggle', [SmtpCredentialController::class, 'toggle']);
        Route::post('smtp-credentials/{smtp_credential}/test', [SmtpCredentialController::class, 'test']);

        Route::get('smtp-credentials/{smtp_credential}/receiver-settings', [SmtpCredentialController::class, 'receiverSettings']);
        Route::put('smtp-credentials/{smtp_credential}/receiver-settings', [SmtpCredentialController::class, 'updateReceiverSettings']);
        Route::get('smtp-credentials/{smtp_credential}/receiver-preview', [SmtpCredentialController::class, 'previewReceiverBatch']);
        Route::post('smtp-credentials/{smtp_credential}/receiver-message-preview', [SmtpCredentialController::class, 'previewReceiverMessage']);
        Route::get('smtp-credentials/{smtp_credential}/receiver-template-source', [SmtpCredentialController::class, 'receiverTemplateSource']);
        Route::post('smtp-credentials/{smtp_credential}/receiver-run', [SmtpCredentialController::class, 'runReceiverCampaign']);
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
        // Email address validation (SendGrid). READ-ONLY: stats and the audit
        // log. No re-run action by design — see EmailValidationLogController.
        // Literal segments before any parameter route, per the file's rule.
        // Ad-hoc single-address check. THROTTLED even though it is behind admin
        // auth: it spends a real credit per call from the shared monthly budget,
        // and a stuck form or an impatient operator should not be able to empty
        // it. Cache, cooldown and quota all still apply.
        Route::post('email-validation/check', [EmailValidationCheckController::class, 'store'])
            ->middleware('throttle:20,1');
        Route::get('email-validation/stats', [EmailValidationStatsController::class, 'index']);
        Route::get('email-validation/count', [EmailValidationLogController::class, 'count']);
        Route::get('email-validation/analysis', [EmailValidationLogController::class, 'analysis']);
        Route::get('email-validation/export', [EmailValidationLogController::class, 'export']);
        Route::get('email-validation', [EmailValidationLogController::class, 'index']);

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

    // ── Mailgun receiver unsubscribe (keyless, token is the credential) ──────
    // POST acts immediately: it is the List-Unsubscribe-Post target.
    // GET only RENDERS a confirm page — mail clients and scanners prefetch GET
    // links, and an acting GET would unsubscribe people who never clicked. Same
    // reasoning as the newsletter routes above.
    Route::post('mailgun-unsubscribe/{token}', [MailgunUnsubscribeController::class, 'oneClick'])
        ->middleware('throttle:60,1');
    Route::get('mailgun-unsubscribe/{token}', [MailgunUnsubscribeController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::post('mailgun-unsubscribe/{token}/confirm', [MailgunUnsubscribeController::class, 'confirm'])
        ->middleware('throttle:60,1');

    // Mailgun delivery events. Public by necessity — Mailgun sends no bearer
    // token — so the HMAC signature is the only authentication; see the
    // controller. Throttled generously: a large send produces many events.
    Route::post('mailgun-webhook', MailgunWebhookController::class)
        ->middleware('throttle:600,1');

    // ── Double opt-in verify (keyless, token is the credential) ──────────────
    // Target of the verify link in the verify email. POST-only for parity with
    // one-click unsubscribe (GET links get prefetched). Not behind verify.site:
    // the opaque subscription token resolves the subscriber across all sites.
    Route::post('verify/{token}', [PublicVerifyController::class, 'verify'])
        ->middleware('throttle:60,1');

    // ── Public (site-keyed) ──────────────────────────────────────────────
    Route::prefix('public/sites/{site}')->middleware('verify.site')->group(function () {
        // Literal segment BEFORE casinos/{slug}, or "facets" is read as a slug.
        Route::get('casinos/facets',          [PublicCasinoController::class, 'facets']);
        Route::get('casinos',                 [PublicCasinoController::class, 'index']);
        Route::get('casinos/{slug}',          [PublicCasinoController::class, 'show']);

        Route::get('categories',              [PublicCategoryController::class, 'index']);
        Route::get('categories/{slug}',       [PublicCategoryController::class, 'show']);

        // Which optional surfaces this site publishes. Advisory — it tells the
        // front end what to render; the endpoints below enforce it themselves.
        Route::get('features',                [SiteFeatureController::class, 'index']);

        // The whole grid in one call: continents in order, each with its
        // countries — the front end renders headings and cards together.
        // Both 404 unless the site has countries_enabled.
        Route::get('countries',               [PublicCountryController::class, 'index']);
        Route::get('countries/{slug}',        [PublicCountryController::class, 'show']);

        // The forum: every published review on this site, grouped by casino.
        // Declared before the per-casino routes below so it reads in feature
        // order; there is no prefix collision between them.
        Route::get('reviews',                 [PublicCasinoReviewController::class, 'feed']);

        // Visitor reviews for one casino. Both 404 unless the site has
        // reviews_enabled. The write path is throttled hard — it is an open form.
        Route::get('casinos/{casinoSlug}/reviews', [PublicCasinoReviewController::class, 'index']);
        Route::post('casinos/{casinoSlug}/reviews', [PublicCasinoReviewController::class, 'store'])
            ->middleware('throttle:10,1');

        // Site-wide search for the header overlay. Inside the verify.site group,
        // so the site is resolved from X-Site-Key and never from the request —
        // a client-supplied site id would let one key read another's index.
        //
        // Throttled harder than the group default because the overlay calls it
        // on every keystroke: 60/min is roughly one debounced request per second
        // sustained, which is well above real typing and well below abuse.
        Route::get('search/suggest', [PublicSearchController::class, 'suggest'])
            ->middleware('throttle:60,1');

        Route::get('special-offers',          [PublicSpecialOfferController::class, 'index']);
        Route::get('special-offers/{slug}',   [PublicSpecialOfferController::class, 'show']);

        // Header and footer menus in one response — the layout renders both on
        // every page. Empty means "use the links in code".
        Route::get('navigation',              [PublicNavigationController::class, 'index']);
        // Read by the site's middleware on cache-miss, so the payload is kept
        // to the three fields it actually matches on.
        Route::get('redirects',               [PublicRedirectController::class, 'index']);

        // Who stands behind the reviews. Separate from `features`: that endpoint
        // is booleans, this is content.
        Route::get('editorial',               [EditorialController::class, 'index']);

        // Published guides. Both 404 unless the site has guides_enabled — the
        // endpoints enforce it themselves, as countries and reviews do.
        Route::get('articles',                [PublicArticleController::class, 'index']);
        Route::get('articles/{slug}',         [PublicArticleController::class, 'show']);

        // Published news. Same table as guides, different section — both 404
        // unless the site has news_enabled.
        // The Bonus area: categories with their offers. Drives both the header
        // dropdown and the home page sections. 404s unless bonus_enabled.
        Route::get('bonus',                   [PublicBonusController::class, 'index']);

        Route::get('news',                    [PublicNewsController::class, 'index']);
        // BEFORE news/{slug}, or `featured` is swallowed as a slug and the home
        // page asks for a post that does not exist.
        Route::get('news/featured',           [PublicNewsController::class, 'featured']);
        Route::get('news/{slug}',             [PublicNewsController::class, 'show']);

        /*
         * ── The community forum ────────────────────────────────────────────
         *
         * Every route 404s unless the site has `forum_enabled`; the controllers
         * enforce that themselves, as countries, guides and news do.
         *
         * ORDERING MATTERS HERE. `forum/members/...` and `forum/posts/...` are
         * literal segments that share a prefix with `forum/{categorySlug}`, so
         * they are declared FIRST — otherwise "members" is swallowed as a
         * category slug and the route 404s for a reason nothing explains.
         */
        Route::get('forum', [PublicForumController::class, 'index']);

        // --- accounts -------------------------------------------------------
        // Outside `auth:forum`: these are how a member GETS a token.
        Route::post('forum/members/register', [ForumAuthController::class, 'register'])
            ->middleware('throttle:forum-register');
        Route::post('forum/members/login', [ForumAuthController::class, 'login'])
            // Same shape as the admin login limiter: tight, IP-keyed, and the
            // endpoint returns one generic message either way so it cannot be
            // used to enumerate addresses.
            ->middleware('throttle:6,1');
        Route::post('forum/members/forgot-password', [ForumAuthController::class, 'forgotPassword'])
            ->middleware('throttle:6,1');
        Route::post('forum/members/reset-password', [ForumAuthController::class, 'resetPassword'])
            ->middleware('throttle:6,1');
        /*
         * POST, not GET, and named so the signed URL can be built.
         *
         * The platform's other mail-link endpoints are POST-only for the same
         * reason: mail clients prefetch GET links, which would confirm addresses
         * nobody ever clicked.
         */
        Route::post('forum/members/{member}/verify', [ForumAuthController::class, 'verify'])
            ->name('forum.verify')
            ->middleware('throttle:10,1');

        Route::middleware('auth:forum')->group(function (): void {
            Route::get('forum/members/me', [ForumAuthController::class, 'me']);
            Route::post('forum/members/logout', [ForumAuthController::class, 'logout']);
        });

        // --- writes ---------------------------------------------------------
        // Reporting is open to guests deliberately — see the controller.
        Route::post('forum/posts/{post}/report', [ForumPostController::class, 'report'])
            ->middleware('throttle:forum-report');

        Route::post('forum/articles/{slug}/posts', [ForumPostController::class, 'store'])
            ->middleware(['auth:forum', 'throttle:forum-post']);

        // --- reads, parameterised last --------------------------------------
        Route::get('forum/{categorySlug}', [PublicForumController::class, 'category']);
        Route::get('forum/{categorySlug}/{slug}', [PublicForumController::class, 'article']);

        // Click counting for the site's own /go redirect route. Called
        // server-side by that route handler, never from a browser — see the
        // controller. Throttled because it is a write on a public prefix.
        Route::post('affiliate-clicks',       [AffiliateClickController::class, 'store'])
            ->middleware('throttle:120,1');

        Route::get('social-links',            [PublicSocialLinkController::class, 'index']);

        // CMS / Legal pages (published only)
        Route::get('pages/{slug}',            [PublicCmsPageController::class, 'show']);

        // Newsletter signup + one-click unsubscribe (token-based)
        // THROTTLED, and it must stay that way. This endpoint now spends a paid
        // SendGrid validation credit per new address (2,500/month across all six
        // sites), and `verify.site` does NOT rate-limit despite what its own
        // docs used to claim — so without these two limits a script can drain a
        // month of credits in minutes. Two windows: a burst limit and an hourly
        // ceiling, both per IP.
        Route::post('newsletter', [PublicNewsletterController::class, 'store'])
            ->middleware('throttle:subscribe');
        Route::post('newsletter/unsubscribe', [PublicNewsletterController::class, 'unsubscribe']);
    });
});

/*
|--------------------------------------------------------------------------
| UniOne — ADDITIVE BLOCK
|--------------------------------------------------------------------------
|
| Everything below is new and self-contained. It adds routes; it changes none.
| No existing route, middleware, controller or prefix above is touched — this is
| the only edit made to this file by the UniOne feature, and it is an append.
|
| Two groups:
|   /api/v1/admin/unione/*   behind auth:sanctum, like every other admin route
|   /api/v1/unione/webhook/* public by necessity — UniOne posts to it
|
| The webhook carries a per-key TOKEN in the path (gate 1) and is verified
| against the MD5-with-api-key hash UniOne documents (gate 2). See
| UniOneWebhookVerifier for why `webhook_secret` is a path token and not a
| signing secret.
*/
Route::prefix('v1')->group(function (): void {
    Route::prefix('admin/unione')->middleware('auth:sanctum')->group(function (): void {
        // ── keys ────────────────────────────────────────────────────────────
        // Literal segments before anything parameterised, per the platform
        // convention — `keys/{key}` would otherwise swallow them.
        Route::get('keys', [UniOneKeyController::class, 'index']);
        Route::post('keys', [UniOneKeyController::class, 'store']);
        Route::put('keys/{uniOneKey}', [UniOneKeyController::class, 'update']);
        Route::delete('keys/{uniOneKey}', [UniOneKeyController::class, 'destroy']);
        Route::patch('keys/{uniOneKey}/toggle', [UniOneKeyController::class, 'toggle']);
        Route::post('keys/{uniOneKey}/verify', [UniOneKeyController::class, 'verify']);
        Route::patch('keys/{uniOneKey}/default', [UniOneKeyController::class, 'makeDefault']);

        // Helper tabs, read-through to UniOne with the selected key.
        Route::get('keys/{uniOneKey}/domains', [UniOneKeyController::class, 'domains']);
        Route::post('keys/{uniOneKey}/domains/dns', [UniOneKeyController::class, 'domainDns']);
        Route::post('keys/{uniOneKey}/domains/recheck', [UniOneKeyController::class, 'recheckDomain']);
        Route::get('keys/{uniOneKey}/suppressions', [UniOneKeyController::class, 'suppressions']);

        // ── receivers ───────────────────────────────────────────────────────
        Route::get('receivers/stats', [UniOneReceiverController::class, 'stats']);
        Route::get('receivers/export', [UniOneReceiverController::class, 'export']);
        Route::post('receivers/import', [UniOneReceiverController::class, 'import']);
        Route::post('receivers/bulk', [UniOneReceiverController::class, 'bulk']);
        Route::get('receivers', [UniOneReceiverController::class, 'index']);
        Route::post('receivers', [UniOneReceiverController::class, 'store']);
        Route::put('receivers/{uniOneReceiver}', [UniOneReceiverController::class, 'update']);
        Route::delete('receivers/{uniOneReceiver}', [UniOneReceiverController::class, 'destroy']);

        // ── sending and the log ─────────────────────────────────────────────
        Route::get('sends/templates', [UniOneSendController::class, 'templates']);
        Route::post('sends/preview', [UniOneSendController::class, 'preview']);
        Route::post('sends/test', [UniOneSendController::class, 'test']);
        Route::get('sends/export', [UniOneSendController::class, 'export']);
        Route::get('sends', [UniOneSendController::class, 'index']);
        Route::post('sends', [UniOneSendController::class, 'store']);
        Route::get('sends/{uniOneSend}', [UniOneSendController::class, 'show']);
    });

    /*
     * Public by necessity. Throttled because it is an unauthenticated POST:
     * the token and the hash both reject impostors, but a flood of rejections
     * is still work we should not do unboundedly.
     */
    Route::post('unione/webhook/{token}', UniOneWebhookController::class)
        ->middleware('throttle:300,1');
});
