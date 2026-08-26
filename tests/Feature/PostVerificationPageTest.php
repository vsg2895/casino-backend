<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\VerificationPromotionEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The page a subscriber lands on after clicking their verification link.
 *
 * Two halves, tested where each actually lives:
 *
 *  - THE CONTRACT. `bonus_email_expected` is the single signal the page uses to
 *    decide whether to promise an incoming email. It must be true only when one
 *    is genuinely being sent, or the page lies to the subscriber.
 *  - THE PAGE SOURCE. The four Next.js copies are asserted directly: no leftover
 *    template markers, no addresses, no delay, and the unsubscribe link built
 *    from the existing route and token. These are static properties, so checking
 *    the source is a real guard and needs no JS toolchain.
 *
 * NO REAL EMAIL, NO REAL DATABASE — `Tests\TestCase` enforces both.
 */
class PostVerificationPageTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @var list<string> */
    private const SITES = ['idevaffiliation', 'winpalack', 'roulettingo', 'viglinksi'];

    private function pagePath(string $site): string
    {
        return base_path("../sites/{$site}/src/app/verify/[token]/page.tsx");
    }

    private function pageSource(string $site): string
    {
        $path = $this->pagePath($site);
        $this->assertFileExists($path, "{$site} must have a verify page");

        return (string) file_get_contents($path);
    }

    // ── The contract the page reads ──────────────────────────────────────────

    public function test_a_first_verification_promises_the_bonus_email(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);
        VerificationPromotionEmail::current()->update(['active' => true]);

        $this->postJson('/api/v1/verify/' . $sub->unsubscribe_token)
            ->assertOk()
            ->assertJson(['ok' => true, 'bonus_email_expected' => true]);

        $this->assertTrue($sub->refresh()->verified);
    }

    public function test_a_second_visit_does_not_promise_another_bonus(): void
    {
        // Re-opening the link must not tell them a second email is coming — none
        // is sent, because only the first click triggers the promotion.
        Mail::fake();
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);
        VerificationPromotionEmail::current()->update(['active' => true]);

        $this->postJson('/api/v1/verify/' . $sub->unsubscribe_token)
            ->assertJson(['bonus_email_expected' => true]);

        $this->postJson('/api/v1/verify/' . $sub->unsubscribe_token)
            ->assertOk()
            ->assertJson(['ok' => true, 'bonus_email_expected' => false]);
    }

    public function test_a_disabled_promotion_never_promises_an_email(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);
        VerificationPromotionEmail::current()->update(['active' => false]);

        $this->postJson('/api/v1/verify/' . $sub->unsubscribe_token)
            ->assertOk()
            ->assertJson(['ok' => true, 'bonus_email_expected' => false]);

        // The subscriber is still verified — only the promise is withheld.
        $this->assertTrue($sub->refresh()->verified);
    }

    public function test_an_unknown_token_promises_nothing(): void
    {
        VerificationPromotionEmail::current()->update(['active' => true]);

        $this->postJson('/api/v1/verify/' . str_repeat('a', 64))
            ->assertOk()
            ->assertJson(['ok' => true, 'bonus_email_expected' => false]);
    }

    public function test_verification_still_records_the_first_click_only(): void
    {
        // The delay is measured from verified_at, so a re-click must not move it.
        Mail::fake();
        [$site] = $this->siteWithKey();
        $sub = Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);

        $this->postJson('/api/v1/verify/' . $sub->unsubscribe_token)->assertOk();
        $first = $sub->refresh()->verified_at;

        $this->postJson('/api/v1/verify/' . $sub->unsubscribe_token)->assertOk();

        $this->assertEquals($first, $sub->refresh()->verified_at);
    }

    // ── The page source, for all four sites ──────────────────────────────────

    public function test_no_template_markers_survive_in_any_page(): void
    {
        foreach (self::SITES as $site) {
            $source = $this->pageSource($site);

            foreach (['{{ site_url }}', '{{ unsubscribe_url }}', '{{site_url}}', '{{unsubscribe_url}}'] as $marker) {
                $this->assertStringNotContainsString($marker, $source, "{$site} still has {$marker}");
            }
        }
    }

    public function test_no_page_prints_an_email_address(): void
    {
        // Neither the subscriber's nor the sender's — the page has no business
        // showing either, and the API never sends them.
        foreach (self::SITES as $site) {
            $this->assertDoesNotMatchRegularExpression(
                '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
                $this->pageSource($site),
                "{$site} must not contain an email address",
            );
        }
    }

    public function test_no_page_displays_a_delay(): void
    {
        foreach (self::SITES as $site) {
            $source = $this->pageSource($site);

            $this->assertStringNotContainsString('delay_minutes', $source);
            $this->assertStringNotContainsString('delayMinutes', $source);
            $this->assertDoesNotMatchRegularExpression('/\bminutes?\b/i', $source, "{$site} must not mention minutes");
        }
    }

    public function test_the_unsubscribe_link_reuses_the_existing_route_and_token(): void
    {
        foreach (self::SITES as $site) {
            $source = $this->pageSource($site);

            // The existing public route, and the token already in this URL — no
            // new route, no new token.
            $this->assertStringContainsString('/unsubscribe/${encodeURIComponent(token)}', $source);
            $this->assertStringContainsString('href={unsubscribeHref}', $source);
        }
    }

    public function test_every_page_is_gated_on_the_api_signal(): void
    {
        foreach (self::SITES as $site) {
            $source = $this->pageSource($site);

            // The incoming-email section and the Promotions/Spam guidance only
            // render when the API says an email is genuinely coming.
            $this->assertStringContainsString('bonusEmailExpected', $source);
            $this->assertStringContainsString('Promotions', $source);
            $this->assertStringContainsString('Not spam', $source);
            $this->assertStringContainsString('Add us to your contacts', $source);
        }
    }

    public function test_no_page_hardcodes_a_brand_name(): void
    {
        // Shared across four sites — the name must come from the site's own env.
        foreach (self::SITES as $site) {
            $source = $this->pageSource($site);

            $this->assertStringContainsString('NEXT_PUBLIC_SITE_NAME', $source);

            foreach (['WinPalack', 'Winpalack', 'winpalack'] as $brand) {
                $this->assertStringNotContainsString($brand, $source, "{$site} hardcodes {$brand}");
            }
        }
    }

    public function test_every_page_keeps_its_own_accent(): void
    {
        $accents = [
            'idevaffiliation' => '#4f46e5',
            'winpalack'       => '#065f46',
            'roulettingo'     => '#a52f27',
            'viglinksi'       => '#17111f',
        ];

        foreach ($accents as $site => $accent) {
            $this->assertStringContainsString(
                "const ACCENT = '{$accent}'",
                $this->pageSource($site),
                "{$site} must keep its own brand colour",
            );
        }
    }

    public function test_every_page_stays_accessible_and_responsive(): void
    {
        foreach (self::SITES as $site) {
            $source = $this->pageSource($site);

            $this->assertStringContainsString('focus-visible:ring-2', $source, "{$site} needs a visible focus ring");
            $this->assertStringContainsString('motion-reduce:transition-none', $source, "{$site} must respect reduced motion");
            $this->assertStringContainsString('max-w-md', $source, "{$site} must stay readable on mobile");
        }
    }
}
