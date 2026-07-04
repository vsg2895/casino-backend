<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\NewsletterSubscribedMail;
use App\Mail\PromotionEmail;
use App\Models\Newsletter;
use App\Models\Unsubscribe;
use App\Services\PromotionEmailService;
use App\Services\SubscriptionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * RFC 8058 one-click unsubscribe: the keyless POST endpoint + the
 * List-Unsubscribe headers that point at it, and the fact that admin test sends
 * now carry a real, working token.
 */
class OneClickUnsubscribeTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sendgrid.from_domain', 'mail.test');
    }

    // ── Keyless one-click endpoint ────────────────────────────────────────

    public function test_one_click_records_subscription_opt_out_without_a_site_key(): void
    {
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.com']);

        // No X-Site-Key, no slug — the opaque token is the only credential.
        $this->postJson('/api/v1/unsubscribe/' . $sub->unsubscribe_token)
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue(Unsubscribe::has($site->id, 'a@example.com', Unsubscribe::TYPE_SUBSCRIPTION));
        $this->assertFalse(Unsubscribe::has($site->id, 'a@example.com', Unsubscribe::TYPE_PROMOTION));
    }

    public function test_one_click_records_promotion_opt_out(): void
    {
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'b@example.com']);

        $this->postJson('/api/v1/unsubscribe/' . $sub->promotion_unsubscribe_token)
            ->assertOk();

        $this->assertTrue(Unsubscribe::has($site->id, 'b@example.com', Unsubscribe::TYPE_PROMOTION));
    }

    public function test_one_click_hides_unknown_tokens_and_records_nothing(): void
    {
        $this->postJson('/api/v1/unsubscribe/' . str_repeat('0', 64))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseCount('unsubscribes', 0);
    }

    public function test_one_click_rejects_get_to_avoid_prefetch_unsubscribes(): void
    {
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'c@example.com']);

        $this->get('/api/v1/unsubscribe/' . $sub->unsubscribe_token)->assertStatus(405);
        $this->assertDatabaseCount('unsubscribes', 0);
    }

    // ── List-Unsubscribe headers ──────────────────────────────────────────

    public function test_subscription_email_carries_one_click_headers(): void
    {
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'd@example.com']);

        $mail = app(SubscriptionEmailService::class)->mailForSubscriber($site, $sub);
        $headers = $mail->headers()->text;

        $this->assertSame(
            '<' . Unsubscribe::oneClickUrl((string) $sub->unsubscribe_token) . '>',
            $headers['List-Unsubscribe'],
        );
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    public function test_promotion_email_carries_one_click_headers_with_promotion_token(): void
    {
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'e@example.com']);

        $mail = app(PromotionEmailService::class)->mailForSubscriber($site, $site->promotionEmailOrDefault(), $sub);
        $headers = $mail->headers()->text;

        $this->assertStringContainsString((string) $sub->promotion_unsubscribe_token, $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    // ── Admin test send now carries a working token ───────────────────────

    public function test_admin_test_send_registers_subscriber_and_uses_real_token(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/email-template/test",
            ['to' => 'tester@example.com'],
        )->assertOk()->assertJson(['ok' => true]);

        // The test recipient is now a subscriber with a real token.
        $this->assertDatabaseHas('newsletters', ['site_id' => $site->id, 'email' => 'tester@example.com']);
        $sub = Newsletter::where('site_id', $site->id)->where('email', 'tester@example.com')->firstOrFail();

        Mail::assertSent(NewsletterSubscribedMail::class, function (NewsletterSubscribedMail $mail) use ($sub): bool {
            return str_contains($mail->unsubscribeUrl, (string) $sub->unsubscribe_token)
                && ! str_contains($mail->unsubscribeUrl, str_repeat('0', 64))
                && $mail->headers()->text['List-Unsubscribe'] === '<' . Unsubscribe::oneClickUrl((string) $sub->unsubscribe_token) . '>';
        });
    }

    public function test_promotion_test_send_uses_real_promotion_token(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/test",
            ['to' => 'tester@example.com'],
        )->assertOk();

        $sub = Newsletter::where('site_id', $site->id)->where('email', 'tester@example.com')->firstOrFail();

        Mail::assertSent(PromotionEmail::class, fn (PromotionEmail $mail): bool =>
            str_contains($mail->unsubscribeUrl, (string) $sub->promotion_unsubscribe_token));
    }
}
