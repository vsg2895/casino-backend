<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionOverride;
use App\Services\PostVerificationPromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Per-site image + link overrides on the Promotion After Verification email.
 *
 * The sibling of VerificationPromotionBrandingTest, which guards that every
 * site renders IDENTICAL branding. That still holds — these tests add the one
 * thing a site may now change, and pin down how narrow it is:
 *
 *  1. a site with an override gets its own hero image and link targets;
 *  2. every OTHER site is untouched;
 *  3. the visible TEXT is byte-identical either way — this feature may not
 *     alter a single word, which is the requirement it was built under;
 *  4. footer links are re-POINTED, keeping their labels;
 *  5. an empty or missing override falls back to the default completely.
 */
class VerificationPromotionSiteOverrideTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function render(Site $site): string
    {
        return app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, VerificationPromotionEmail::current(), 'sub@example.com')
            ->render();
    }

    /** Visible words only — tags, and the per-recipient unsubscribe URL, removed. */
    private function visibleText(string $html): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $html) ?? '';

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function hrefs(string $html): array
    {
        preg_match_all('/href="([^"]+)"/', $html, $m);

        return $m[1];
    }

    public function test_an_overridden_site_gets_its_own_image_and_links(): void
    {
        [$overridden] = $this->siteWithKey(['slug' => 'roulettingo', 'domain' => 'roulettingo.test']);

        // The top button has no LABEL by default, and a button with no label is
        // not rendered at all — so give it one, or there is no href to assert
        // on and the test would pass for the wrong reason.
        VerificationPromotionEmail::current()
            ->forceFill(['top_button_text' => 'Get Bonus'])
            ->save();

        VerificationPromotionOverride::query()->create([
            'site_id'        => $overridden->id,
            'hero_image_url' => 'https://cdn.example.test/custom-hero.png',
            'hero_url'       => 'https://example.test/hero',
            'top_button_url' => 'https://example.test/top',
            'cta_button_url' => 'https://example.test/cta',
        ]);

        $html = $this->render($overridden);

        $this->assertStringContainsString('https://cdn.example.test/custom-hero.png', $html);
        $this->assertContains('https://example.test/cta', $this->hrefs($html));
        $this->assertContains('https://example.test/top', $this->hrefs($html));
    }

    public function test_other_sites_are_completely_unaffected(): void
    {
        [$overridden] = $this->siteWithKey(['slug' => 'roulettingo', 'domain' => 'roulettingo.test']);
        [$other] = $this->siteWithKey(['slug' => 'winpalack', 'domain' => 'winpalack.test']);

        VerificationPromotionOverride::query()->create([
            'site_id'        => $overridden->id,
            'hero_image_url' => 'https://cdn.example.test/custom-hero.png',
            'cta_button_url' => 'https://example.test/cta',
        ]);

        $html = $this->render($other);

        $this->assertStringNotContainsString('cdn.example.test', $html);
        $this->assertNotContains('https://example.test/cta', $this->hrefs($html));
    }

    /**
     * THE constraint this feature was built under: only the image and the link
     * targets may differ. Not one word of the email may change.
     */
    public function test_the_visible_text_is_identical_with_and_without_an_override(): void
    {
        [$overridden] = $this->siteWithKey(['slug' => 'roulettingo', 'domain' => 'roulettingo.test']);
        [$other] = $this->siteWithKey(['slug' => 'winpalack', 'domain' => 'winpalack.test']);

        VerificationPromotionOverride::query()->create([
            'site_id'          => $overridden->id,
            'hero_image_url'   => 'https://cdn.example.test/custom-hero.png',
            'hero_url'         => 'https://example.test/hero',
            'top_button_url'   => 'https://example.test/top',
            'cta_button_url'   => 'https://example.test/cta',
            'footer_link_urls' => ['https://example.test/about'],
        ]);

        // The unsubscribe URL is per site and per recipient by design, so it is
        // removed before comparing — everything else must match exactly.
        $strip = fn (string $t): string => (string) preg_replace('#https?://\S+#', '', $t);

        $this->assertSame(
            $strip($this->visibleText($this->render($other))),
            $strip($this->visibleText($this->render($overridden))),
        );
    }

    public function test_a_footer_link_keeps_its_label_and_only_changes_target(): void
    {
        [$site] = $this->siteWithKey(['slug' => 'roulettingo', 'domain' => 'roulettingo.test']);

        $template = VerificationPromotionEmail::current();
        $template->forceFill(['footer_links' => [
            ['label' => 'About', 'url' => 'https://default.test/about'],
            ['label' => 'Contact', 'url' => 'https://default.test/contact'],
        ]])->save();

        VerificationPromotionOverride::query()->create([
            'site_id' => $site->id,
            // Re-point the FIRST link only; the second keeps its default.
            'footer_link_urls' => ['https://example.test/about', null],
        ]);

        $html = $this->render($site);

        $this->assertStringContainsString('About', $html);
        $this->assertStringContainsString('Contact', $html);
        $this->assertContains('https://example.test/about', $this->hrefs($html));
        $this->assertContains('https://default.test/contact', $this->hrefs($html));
        $this->assertNotContains('https://default.test/about', $this->hrefs($html));
    }

    public function test_a_blank_override_falls_back_to_the_default(): void
    {
        [$site] = $this->siteWithKey(['slug' => 'roulettingo', 'domain' => 'roulettingo.test']);

        VerificationPromotionOverride::query()->create([
            'site_id'        => $site->id,
            'hero_image_url' => '',
            'cta_button_url' => null,
        ]);

        [$bare] = $this->siteWithKey(['slug' => 'viglinksi', 'domain' => 'viglinksi.test']);

        $strip = fn (string $t): string => (string) preg_replace('#https?://\S+#', '', $t);

        $this->assertSame(
            $strip($this->visibleText($this->render($bare))),
            $strip($this->visibleText($this->render($site))),
        );
    }

    public function test_the_override_carries_no_text_column_at_all(): void
    {
        // A structural guard, not a behavioural one: if somebody ever adds a
        // heading or label column here, this feature silently becomes a way to
        // change the email's words per site. Fail loudly instead.
        foreach (['heading', 'subject', 'cta_button_text', 'top_button_text', 'intro_text'] as $column) {
            $this->assertFalse(
                \Illuminate\Support\Facades\Schema::hasColumn('verification_promotion_overrides', $column),
                "verification_promotion_overrides must never carry a text column, found: {$column}",
            );
        }
    }
}
