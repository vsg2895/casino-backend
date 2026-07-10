<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessNewsletterSubscription;
use App\Jobs\SendNewsletterWelcomeEmail;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Services\PromotionEmailService;
use App\Services\SubscriptionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Per-stream unsubscribe: a subscriber can opt out of the subscription stream
 * and the promotion stream independently. The URL carries only an opaque token
 * (no email/id), each opt-out is logged in `unsubscribes` (email + when + type),
 * and the subscriber row survives so the other stream keeps working.
 */
class NewsletterUnsubscribeTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sendgrid.from_domain', 'mail.test');
    }

    private function unsubscribe(Site $site, string $key, string $token): void
    {
        $this->postJson(
            $this->publicBase($site) . '/newsletter/unsubscribe',
            ['token' => $token],
            $this->siteHeaders($key),
        )->assertOk()->assertJson(['ok' => true]);
    }

    // ── Per-stream recording ──────────────────────────────────────────────

    public function test_subscription_token_records_a_subscription_opt_out(): void
    {
        [$site, $key] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.com']);

        $this->unsubscribe($site, $key, (string) $sub->unsubscribe_token);

        $this->assertTrue(Unsubscribe::has($site->id, 'a@example.com', Unsubscribe::TYPE_SUBSCRIPTION));
        $this->assertFalse(Unsubscribe::has($site->id, 'a@example.com', Unsubscribe::TYPE_PROMOTION));

        // Logs the email + a timestamp; leaves the subscriber intact.
        $row = Unsubscribe::first();
        $this->assertSame('a@example.com', $row->email);
        $this->assertNotNull($row->unsubscribed_at);
        $this->assertNotSoftDeleted('newsletters', ['id' => $sub->id]);
    }

    public function test_promotion_token_records_a_promotion_opt_out(): void
    {
        [$site, $key] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'b@example.com']);

        $this->unsubscribe($site, $key, (string) $sub->promotion_unsubscribe_token);

        $this->assertTrue(Unsubscribe::has($site->id, 'b@example.com', Unsubscribe::TYPE_PROMOTION));
        $this->assertFalse(Unsubscribe::has($site->id, 'b@example.com', Unsubscribe::TYPE_SUBSCRIPTION));
    }

    public function test_streams_are_independent(): void
    {
        [$site, $key] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'c@example.com']);

        // Opt out of subscription only …
        $this->unsubscribe($site, $key, (string) $sub->unsubscribe_token);
        // … then out of promotion too — both recorded, one row each.
        $this->unsubscribe($site, $key, (string) $sub->promotion_unsubscribe_token);

        $this->assertDatabaseCount('unsubscribes', 2);
        $this->assertTrue(Unsubscribe::has($site->id, 'c@example.com', Unsubscribe::TYPE_SUBSCRIPTION));
        $this->assertTrue(Unsubscribe::has($site->id, 'c@example.com', Unsubscribe::TYPE_PROMOTION));
    }

    public function test_the_two_tokens_differ(): void
    {
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'd@example.com']);

        $this->assertNotSame($sub->unsubscribe_token, $sub->promotion_unsubscribe_token);
        $this->assertSame(64, strlen((string) $sub->promotion_unsubscribe_token));
    }

    public function test_recording_is_idempotent(): void
    {
        [$site, $key] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'e@example.com']);

        $this->unsubscribe($site, $key, (string) $sub->unsubscribe_token);
        $this->unsubscribe($site, $key, (string) $sub->unsubscribe_token);

        $this->assertDatabaseCount('unsubscribes', 1);
    }

    // ── URL is opaque + supports localhost ────────────────────────────────

    public function test_unsubscribe_url_leaks_no_personal_data(): void
    {
        config()->set('services.unsubscribe.base_url', 'http://localhost:3000');
        [$site] = $this->siteWithKey();
        $site->emailTemplateOrDefault();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'secret@example.com']);

        $mail = app(SubscriptionEmailService::class)->mailForSubscriber($site, $sub);

        // Exactly base + opaque token: no email, id or query string appended,
        // and pointing at localhost for the test.
        $this->assertSame(
            'http://localhost:3000/unsubscribe/' . $sub->unsubscribe_token,
            $mail->unsubscribeUrl,
        );
        $this->assertStringNotContainsString('secret@example.com', $mail->unsubscribeUrl);
    }

    public function test_promotion_email_uses_the_promotion_token(): void
    {
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'promo@example.com']);
        $template = $site->promotionEmailOrDefault();

        $mail = app(PromotionEmailService::class)->mailForSubscriber($site, $template, $sub);

        $this->assertStringContainsString((string) $sub->promotion_unsubscribe_token, $mail->unsubscribeUrl);
        $this->assertStringNotContainsString((string) $sub->unsubscribe_token, $mail->unsubscribeUrl);
    }

    // ── Sending respects the opt-out; re-subscribe clears it ──────────────

    public function test_welcome_email_is_skipped_when_subscription_opted_out(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $site->verifyEmailOrDefault();
        Newsletter::create(['site_id' => $site->id, 'email' => 'gone@example.com']);
        Unsubscribe::record($site->id, 'gone@example.com', Unsubscribe::TYPE_SUBSCRIPTION);

        (new SendNewsletterWelcomeEmail($site->id, 'gone@example.com'))
            ->handle(app(\App\Services\VerifyEmailService::class));

        Mail::assertNothingSent();
    }

    public function test_resubscribing_clears_the_opt_out_and_requeues_welcome(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'again@example.com']);
        Unsubscribe::record($site->id, 'again@example.com', Unsubscribe::TYPE_SUBSCRIPTION);

        (new ProcessNewsletterSubscription($site->id, 'again@example.com'))->handle();

        $this->assertFalse(Unsubscribe::has($site->id, 'again@example.com', Unsubscribe::TYPE_SUBSCRIPTION));
        Queue::assertPushed(SendNewsletterWelcomeEmail::class);
    }
}
