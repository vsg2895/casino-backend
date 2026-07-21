<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendNewsletterWelcomeEmail;
use App\Mail\NewsletterSubscribedMail;
use App\Mail\PromotionEmail;
use App\Mail\VerifyEmailMail;
use App\Models\Newsletter;
use App\Services\VerifyEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Guards the two-transport mail architecture:
 *
 *  - ADMIN mail — the "Send test" buttons (subscription / verify / promotion) —
 *    goes through the admin SMTP mailer (config('mail.admin_mailer')) FROM the
 *    authenticated mailbox (config('mail.from.address')) so a self-hosted server
 *    accepts it, keeping the template's own from_name as the display name.
 *  - PUBLIC verification mail (a visitor subscribing) goes through SendGrid
 *    (config('mail.public_mailer')) with a per-site From domain.
 */
class EmailSendRoutingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** The .env authenticated mailbox (config('mail.from.address')) admin mail uses. */
    private const string ACCOUNT_FROM = 'account@mail.test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('mail.admin_mailer', 'smtp');
        config()->set('mail.public_mailer', 'sendgrid');
        config()->set('mail.from.address', self::ACCOUNT_FROM);
        config()->set('mail.public_from_local_part', 'verify');
        config()->set('mail.site_from_domains', []);
        config()->set('mail.public_from_address', ''); // default: per-site domain (overridden per test)
    }

    // ── Admin test sends → SMTP, FROM the .env mailbox, template display name ──

    public function test_subscription_test_send_uses_admin_smtp_and_account_from(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->emailTemplateOrDefault();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/email-template/test",
            ['to' => 'admin@example.com'],
        )->assertOk()->assertJson(['ok' => true]);

        Mail::assertSent(NewsletterSubscribedMail::class, fn (NewsletterSubscribedMail $mail): bool =>
            $this->assertAdminSend($mail, $template->from_name));
    }

    public function test_promotion_test_send_uses_admin_smtp_and_account_from(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->promotionEmailOrDefault();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/test",
            ['to' => 'admin@example.com'],
        )->assertOk()->assertJson(['ok' => true]);

        Mail::assertSent(PromotionEmail::class, fn (PromotionEmail $mail): bool =>
            $this->assertAdminSend($mail, $template->from_name));
    }

    public function test_verify_test_send_uses_admin_smtp_and_account_from(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->verifyEmailOrDefault();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/verify-email/test",
            ['to' => 'admin@example.com'],
        )->assertOk()->assertJson(['ok' => true]);

        Mail::assertSent(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool =>
            $this->assertAdminSend($mail, $template->from_name));
    }

    /** Every admin test send: SMTP mailer, From the .env mailbox, template name. */
    private function assertAdminSend(NewsletterSubscribedMail|PromotionEmail|VerifyEmailMail $mail, string $fromName): bool
    {
        $from = $mail->envelope()->from;

        return $mail->hasTo('admin@example.com')
            && $mail->mailer === 'smtp'
            && $from?->address === self::ACCOUNT_FROM
            && $from?->name === $fromName;
    }

    // ── Public verification → SendGrid ───────────────────────────────────────

    public function test_public_verification_uses_sendgrid_and_forced_verified_sender(): void
    {
        // Production reality: one authenticated SendGrid domain (winpalack.com),
        // so EVERY site's verify email is sent from that single verified address.
        config()->set('mail.public_from_address', 'noreply@winpalack.com');

        Mail::fake();
        [$site] = $this->siteWithKey(['domain' => 'idevaffiliation.com']);
        $fromName = $site->verifyEmailOrDefault()->from_name; // the site's name
        Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);

        (new SendNewsletterWelcomeEmail($site->id, 'fan@example.com'))
            ->handle(app(VerifyEmailService::class));

        Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail) use ($fromName): bool {
            $from = $mail->envelope()->from;
            return $mail->hasTo('fan@example.com')
                && $mail->mailer === 'sendgrid'
                // Forced verified sender regardless of the subscribing site…
                && $from?->address === 'noreply@winpalack.com'
                // …but the display name still reflects the site.
                && $from?->name === $fromName
                // …and the links in the body point at the subscribing site's real URL.
                && str_contains($mail->verifyUrl, 'https://idevaffiliation.com/verify/');
        });
    }

    public function test_public_verification_falls_back_to_per_site_from_domain(): void
    {
        // With no forced sender, the From domain is resolved from the site.
        config()->set('mail.public_from_address', '');

        Mail::fake();
        [$site] = $this->siteWithKey(['domain' => 'idevaffiliation.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'fan2@example.com']);

        (new SendNewsletterWelcomeEmail($site->id, 'fan2@example.com'))
            ->handle(app(VerifyEmailService::class));

        Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail): bool {
            $from = $mail->envelope()->from;
            return $mail->hasTo('fan2@example.com')
                && $mail->mailer === 'sendgrid'
                && $from?->address === 'verify@idevaffiliation.com';
        });
    }

    // ── Routing is config-driven (overridable per environment) ────────────

    public function test_admin_mailer_is_configurable(): void
    {
        config()->set('mail.admin_mailer', 'array');
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
