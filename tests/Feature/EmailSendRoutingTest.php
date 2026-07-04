<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendNewsletterWelcomeEmail;
use App\Mail\NewsletterSubscribedMail;
use App\Mail\PromotionEmail;
use App\Models\Newsletter;
use App\Services\PromotionEmailService;
use App\Services\SubscriptionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Guards the mail-routing split:
 *
 *  - Admin "Send test" (subscription + promotion) goes through the .env SMTP
 *    mailer (config('mail.test_mailer')) and overrides the sender to the global
 *    MAIL_FROM_ADDRESS so strict SMTP servers accept it.
 *  - Real public-form subscription confirmations go through SendGrid
 *    (config('mail.newsletter_mailer')) and keep the per-site template sender.
 */
class EmailSendRoutingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string GLOBAL_FROM = 'no-reply@platform.test';
    private const string GLOBAL_FROM_NAME = 'Platform Admin';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sendgrid.from_domain', 'mail.test');
        // Deterministic transports + global sender for assertions.
        config()->set('mail.test_mailer', 'smtp');
        config()->set('mail.newsletter_mailer', 'sendgrid');
        config()->set('mail.from.address', self::GLOBAL_FROM);
        config()->set('mail.from.name', self::GLOBAL_FROM_NAME);
    }

    // ── Admin test sends → SMTP + global from ─────────────────────────────

    public function test_subscription_test_send_uses_smtp_mailer_and_global_from(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $site->emailTemplateOrDefault();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/email-template/test",
            ['to' => 'admin@example.com'],
        )->assertOk()->assertJson(['ok' => true]);

        Mail::assertSent(NewsletterSubscribedMail::class, function (NewsletterSubscribedMail $mail): bool {
            return $mail->hasTo('admin@example.com')
                && $mail->mailer === 'smtp'
                && $mail->hasFrom(self::GLOBAL_FROM, self::GLOBAL_FROM_NAME);
        });
    }

    public function test_promotion_test_send_uses_smtp_mailer_and_global_from(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $site->promotionEmailOrDefault();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/test",
            ['to' => 'admin@example.com'],
        )->assertOk()->assertJson(['ok' => true]);

        Mail::assertSent(PromotionEmail::class, function (PromotionEmail $mail): bool {
            return $mail->hasTo('admin@example.com')
                && $mail->mailer === 'smtp'
                && $mail->hasFrom(self::GLOBAL_FROM, self::GLOBAL_FROM_NAME);
        });
    }

    // ── Public subscription → SendGrid + per-site from ────────────────────

    public function test_public_subscription_welcome_uses_sendgrid_and_per_site_from(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $site->emailTemplateOrDefault(); // from_email => offers@mail.test
        Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);

        (new SendNewsletterWelcomeEmail($site->id, 'fan@example.com'))
            ->handle(app(SubscriptionEmailService::class));

        Mail::assertSent(NewsletterSubscribedMail::class, function (NewsletterSubscribedMail $mail): bool {
            return $mail->hasTo('fan@example.com')
                && $mail->mailer === 'sendgrid'
                // Real subscriber mail is NOT overridden to the global from …
                && ! $mail->hasFrom(self::GLOBAL_FROM)
                // … it keeps the per-site template sender (set on the Envelope).
                && $mail->envelope()->from?->address === 'offers@mail.test';
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
