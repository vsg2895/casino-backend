<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendNewsletterWelcomeEmail;
use App\Mail\NewsletterSubscribedMail;
use App\Mail\PromotionEmail;
use App\Models\Newsletter;
use App\Services\SubscriptionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Guards the mail routing + sender-identity split:
 *
 *  - Admin "Send test" (subscription + promotion) goes through the .env SMTP
 *    mailer (config('mail.test_mailer')) using the TEMPLATE's own from_name +
 *    from_email (admin CRUD).
 *  - Real public sends (subscription confirmations + promotion blasts) go through
 *    SendGrid (config('mail.newsletter_mailer')) and force the verified sender
 *    address (config('mail.newsletter_from_address')) while keeping the per-site
 *    from_name — e.g. "Idev Affiliation <info@winpalack.com>".
 */
class EmailSendRoutingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string VERIFIED_FROM = 'verified@mail.test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sendgrid.from_domain', 'mail.test');
        config()->set('mail.test_mailer', 'smtp');
        config()->set('mail.newsletter_mailer', 'sendgrid');
        config()->set('mail.newsletter_from_address', self::VERIFIED_FROM);
    }

    // ── Admin test sends → SMTP + the template's own from (CRUD) ───────────

    public function test_subscription_test_send_uses_smtp_mailer_and_template_from(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->emailTemplateOrDefault();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/email-template/test",
            ['to' => 'admin@example.com'],
        )->assertOk()->assertJson(['ok' => true]);

        Mail::assertSent(NewsletterSubscribedMail::class, function (NewsletterSubscribedMail $mail) use ($template): bool {
            $from = $mail->envelope()->from;
            return $mail->hasTo('admin@example.com')
                && $mail->mailer === 'smtp'
                && $from?->address === $template->from_email
                && $from?->name === $template->from_name;
        });
    }

    public function test_promotion_test_send_uses_smtp_mailer_and_template_from(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->promotionEmailOrDefault();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/test",
            ['to' => 'admin@example.com'],
        )->assertOk()->assertJson(['ok' => true]);

        Mail::assertSent(PromotionEmail::class, function (PromotionEmail $mail) use ($template): bool {
            $from = $mail->envelope()->from;
            return $mail->hasTo('admin@example.com')
                && $mail->mailer === 'smtp'
                && $from?->address === $template->from_email
                && $from?->name === $template->from_name;
        });
    }

    // ── Public subscription → SendGrid + verified address, per-site name ───

    public function test_public_subscription_welcome_uses_sendgrid_and_verified_from(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $fromName = $site->emailTemplateOrDefault()->from_name; // the site's name
        Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);

        (new SendNewsletterWelcomeEmail($site->id, 'fan@example.com'))
            ->handle(app(SubscriptionEmailService::class));

        Mail::assertSent(NewsletterSubscribedMail::class, function (NewsletterSubscribedMail $mail) use ($fromName): bool {
            $from = $mail->envelope()->from;
            return $mail->hasTo('fan@example.com')
                && $mail->mailer === 'sendgrid'
                // Address forced to the verified sender; per-site display name kept.
                && $from?->address === self::VERIFIED_FROM
                && $from?->name === $fromName;
        });
    }

    // ── Routing is config-driven (overridable per environment) ────────────

    public function test_test_mailer_is_configurable(): void
    {
        config()->set('mail.test_mailer', 'array');
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $site->promotionEmailOrDefault();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/test",
            ['to' => 'admin@example.com'],
        )->assertOk();

        Mail::assertSent(
            PromotionEmail::class,
            fn (PromotionEmail $mail): bool => $mail->mailer === 'array',
        );
    }
}
