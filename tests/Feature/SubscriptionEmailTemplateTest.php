<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendNewsletterWelcomeEmail;
use App\Mail\NewsletterSubscribedMail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\SiteEmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class SubscriptionEmailTemplateTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sendgrid.from_domain', 'mail.test');
    }

    // ── Admin template management ─────────────────────────────────────────

    public function test_admin_show_auto_creates_default_template(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->getJson("/api/v1/admin/sites/{$site->id}/email-template")
            ->assertOk()
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.header_title', 'Subscription Confirmed')
            ->assertJsonPath('data.from_domain', 'mail.test');

        $this->assertDatabaseCount('site_email_templates', 1);
    }

    public function test_admin_can_update_template(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $payload = $this->validPayload(['heading' => 'Welcome aboard!']);

        $this->putJson("/api/v1/admin/sites/{$site->id}/email-template", $payload)
            ->assertOk()
            ->assertJsonPath('data.heading', 'Welcome aboard!');

        $this->assertDatabaseHas('site_email_templates', [
            'site_id' => $site->id,
            'heading' => 'Welcome aboard!',
        ]);
    }

    public function test_from_email_accepts_any_valid_address(): void
    {
        // The from-domain lock was removed (SMTP era) so previews/saves no longer
        // break when the configured domain drifts; any valid address is accepted.
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/email-template",
            $this->validPayload(['from_email' => 'offers@some-other-domain.com']),
        )->assertOk()->assertJsonPath('data.from_email', 'offers@some-other-domain.com');
    }

    public function test_from_email_must_be_a_valid_email(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/email-template",
            $this->validPayload(['from_email' => 'not-an-email']),
        )->assertStatus(422)->assertJsonValidationErrorFor('from_email');
    }

    public function test_preview_renders_html_without_persisting(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/email-template/preview",
            $this->validPayload(['header_title' => 'Totally Custom Title']),
        )->assertOk()
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'Totally Custom Title'));

        // Preview must not write.
        $this->assertDatabaseMissing('site_email_templates', ['header_title' => 'Totally Custom Title']);
    }

    public function test_template_endpoints_require_auth(): void
    {
        [$site] = $this->siteWithKey();

        $this->getJson("/api/v1/admin/sites/{$site->id}/email-template")->assertUnauthorized();
    }

    // ── Sending uses the per-site template ────────────────────────────────

    public function test_welcome_job_sends_templated_email_via_sendgrid(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $site->emailTemplateOrDefault();

        $newsletter = Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);

        (new SendNewsletterWelcomeEmail($site->id, 'fan@example.com'))
            ->handle(app(\App\Services\SubscriptionEmailService::class));

        Mail::assertSent(NewsletterSubscribedMail::class, function (NewsletterSubscribedMail $mail) use ($newsletter): bool {
            return $mail->hasTo('fan@example.com')
                && str_contains($mail->unsubscribeUrl, (string) $newsletter->unsubscribe_token);
        });
    }

    public function test_welcome_email_is_skipped_when_template_inactive(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $site->emailTemplateOrDefault()->update(['active' => false]);
        Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);

        (new SendNewsletterWelcomeEmail($site->id, 'fan@example.com'))
            ->handle(app(\App\Services\SubscriptionEmailService::class));

        Mail::assertNothingSent();
    }

    // ── Unsubscribe ───────────────────────────────────────────────────────

    public function test_subscriber_can_unsubscribe_with_token(): void
    {
        [$site, $key] = $this->siteWithKey();
        $newsletter = Newsletter::create(['site_id' => $site->id, 'email' => 'bye@example.com']);

        $this->postJson(
            $this->publicBase($site) . '/newsletter/unsubscribe',
            ['token' => $newsletter->unsubscribe_token],
            $this->siteHeaders($key),
        )->assertOk()->assertJson(['ok' => true]);

        // Per-stream opt-out is recorded; the subscriber row is NOT deleted.
        $this->assertDatabaseHas('unsubscribes', [
            'site_id' => $site->id,
            'email'   => 'bye@example.com',
            'type'    => 'subscription',
        ]);
        $this->assertNotSoftDeleted('newsletters', ['id' => $newsletter->id]);
    }

    public function test_unsubscribe_is_idempotent_for_unknown_token(): void
    {
        [$site, $key] = $this->siteWithKey();

        $this->postJson(
            $this->publicBase($site) . '/newsletter/unsubscribe',
            ['token' => str_repeat('a', 64)],
            $this->siteHeaders($key),
        )->assertOk()->assertJson(['ok' => true]);

        // Unknown token records nothing (never reveals list membership).
        $this->assertDatabaseCount('unsubscribes', 0);
    }

    public function test_unsubscribe_token_is_scoped_to_its_site(): void
    {
        [$siteA] = $this->siteWithKey();
        [$siteB, $keyB] = $this->siteWithKey();
        $newsletter = Newsletter::create(['site_id' => $siteA->id, 'email' => 'x@example.com']);

        // Using site B's key with site A's token must not record an opt-out.
        $this->postJson(
            $this->publicBase($siteB) . '/newsletter/unsubscribe',
            ['token' => $newsletter->unsubscribe_token],
            $this->siteHeaders($keyB),
        )->assertOk();

        $this->assertDatabaseCount('unsubscribes', 0);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return [
            'from_name'         => 'Idev Affiliation',
            'from_email'        => 'offers@mail.test',
            'subject'           => 'Thanks for subscribing to {{site_name}} offers',
            'header_title'      => 'Subscription Confirmed',
            'header_subtitle'   => 'Thanks for joining.',
            'heading'           => 'Thank you for subscribing!',
            'intro_text'        => "You're all set.",
            'offer_text'        => 'Your **bonus** is on the way.',
            'spam_notice'       => 'Check your spam folder.',
            'footer_note'       => 'You subscribed at {{site_name}}.',
            'unsubscribe_label' => 'Unsubscribe',
            'copyright_text'    => '© {{year}} {{site_name}}.',
            'accent_color'      => '#4f46e5',
            'active'            => true,
            ...$overrides,
        ];
    }
}
