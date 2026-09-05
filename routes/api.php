<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\CasinoController as AdminCasinoController;
use App\Http\Controllers\Api\Admin\CasinoSiteAttachmentController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CmsPageController as AdminCmsPageController;
use App\Http\Controllers\Api\Admin\EmailScheduleController;
use App\Http\Controllers\Api\Admin\MediaUploadController;
use App\Http\Controllers\Api\Admin\MailgunKeyController;
use App\Http\Controllers\Api\Admin\MailgunReceiverController;
use App\Http\Controllers\Api\Admin\SmtpCredentialController;
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
use App\Http\Controllers\Api\Admin\VerificationPromotionEmailController;
use App\Http\Controllers\Api\Admin\SocialLinkController as AdminSocialLinkController;
use App\Http\Controllers\Api\Admin\SpecialOfferController as AdminSpecialOfferController;
use App\Http\Controllers\Api\Admin\UnsubscribeController;
use App\Http\Controllers\Api\Admin\WarmupEmailController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Public\CasinoController as PublicCasinoController;
use App\Http\Controllers\Api\Public\CategoryController as PublicCategoryController;
use App\Http\Controllers\Api\Public\CmsPageController as PublicCmsPageController;
use App\Http\Controllers\Api\Public\MailgunUnsubscribeController;
use App\Http\Controllers\Api\Public\MailgunWebhookController;
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
