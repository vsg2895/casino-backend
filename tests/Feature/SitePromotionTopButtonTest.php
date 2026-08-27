<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SitePromotionEmail;
use App\Services\PromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The PER-SITE promotion email's TOP button — the same block the
 * post-verification promotion has, mirrored here.
 *
 * Two things this locks down:
 *
 *  1. POSITION. The button belongs ABOVE the banner, so the offer carries a call
 *     to action before the image rather than only after it — and so it still
 *     reads when a client blocks images.
 *  2. THE FALLBACK CHAIN. `top_button_url` is new, so every row that predates it
 *     is null. Null must keep pointing exactly where it pointed before —
 *     `cta_button_url`, then `hero_url` — or a live template silently changes
 *     its destination on deploy.
 *
 * NO REAL EMAIL, NO REAL DATABASE — enforced by Tests\TestCase.
 */
class SitePromotionTopButtonTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function render(array $attrs): string
    {
        [$site] = $this->siteWithKey();

        $template = $site->promotionEmailOrDefault();
        $template->update($attrs + ['hidden_blocks' => []]);

        return app(PromotionEmailService::class)
            ->previewMail($site, $template->refresh(), 'fan@example.com')
            ->render();
    }

    public function test_the_top_button_renders_above_the_banner(): void
    {
        $html = $this->render([
            'top_button_text' => 'Get Bonus',
            'hero_image_url'  => 'https://cdn.example.com/banner.jpg',
        ]);

        $button = strpos($html, 'Get Bonus');
        $banner = strpos($html, 'cdn.example.com/banner.jpg');

        $this->assertNotFalse($button, 'the top button must render');
        $this->assertNotFalse($banner, 'the banner must render');
        $this->assertLessThan($banner, $button, 'the top button belongs ABOVE the banner');
    }

    public function test_the_top_button_uses_its_own_link_when_set(): void
    {
        $html = $this->render([
            'top_button_text' => 'Get Bonus',
            'top_button_url'  => 'https://affiliate.example/top',
            'cta_button_url'  => 'https://affiliate.example/cta',
            'hero_url'        => 'https://affiliate.example/hero',
        ]);

        $this->assertStringContainsString('https://affiliate.example/top', $html);
    }

    /** A row that predates the column keeps the destination it already had. */
    public function test_an_empty_top_link_falls_back_to_the_cta_link(): void
    {
        $html = $this->render([
            'top_button_text' => 'Get Bonus',
            'top_button_url'  => null,
            'cta_button_url'  => 'https://affiliate.example/cta',
            'hero_url'        => 'https://affiliate.example/hero',
        ]);

        $this->assertStringContainsString('https://affiliate.example/cta', $html);
    }

    /** …and with no CTA link either, the offer link is the last resort. */
    public function test_an_empty_top_and_cta_link_fall_back_to_the_offer_link(): void
    {
        $html = $this->render([
            'top_button_text' => 'Get Bonus',
            'top_button_url'  => null,
            'cta_button_url'  => null,
            'hero_url'        => 'https://affiliate.example/hero',
        ]);

        $this->assertStringContainsString('https://affiliate.example/hero', $html);
    }

    public function test_the_top_link_is_hideable_and_restorable(): void
    {
        [$site] = $this->siteWithKey();
        $template = $site->promotionEmailOrDefault();
        $template->update([
            'top_button_text' => 'Get Bonus',
            'top_button_url'  => 'https://affiliate.example/top',
            'cta_button_url'  => 'https://affiliate.example/cta',
            'hidden_blocks'   => ['top_button_url'],
        ]);

        $service = app(PromotionEmailService::class);
        $hidden = $service->previewMail($site, $template->refresh(), 'fan@example.com')->render();

        // Hidden: the button still renders, but on the fallback destination.
        $this->assertStringContainsString('Get Bonus', $hidden);
        $this->assertStringNotContainsString('https://affiliate.example/top', $hidden);
        $this->assertStringContainsString('https://affiliate.example/cta', $hidden);

        // The URL is KEPT in the row while hidden — that is what makes Restore a
        // toggle rather than a retype.
        $this->assertSame('https://affiliate.example/top', $template->fresh()->top_button_url);

        $template->update(['hidden_blocks' => []]);
        $restored = $service->previewMail($site, $template->refresh(), 'fan@example.com')->render();

        $this->assertStringContainsString('https://affiliate.example/top', $restored);
    }

    public function test_the_admin_round_trip_persists_the_top_link(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $payload = $site->promotionEmailOrDefault()->only(
            array_diff((new SitePromotionEmail)->getFillable(), ['site_id', 'hidden_blocks']),
        );
        $payload['top_button_text'] = 'Get Bonus';
        $payload['top_button_url'] = 'https://affiliate.example/top';

        $this->putJson("/api/v1/admin/sites/{$site->id}/promotion-email", $payload)
            ->assertOk()
            ->assertJsonPath('data.top_button_url', 'https://affiliate.example/top');

        $this->assertSame(
            'https://affiliate.example/top',
            $site->promotionEmailOrDefault()->fresh()->top_button_url,
        );
    }

    /** The field must be offered as a hideable block, or the admin cannot remove it. */
    public function test_the_top_link_is_declared_optional(): void
    {
        $this->assertContains('top_button_url', SitePromotionEmail::OPTIONAL_BLOCKS);
    }

    /** Guards the column width against the URL length the request allows. */
    public function test_the_column_accepts_the_longest_allowed_url(): void
    {
        [$site] = $this->siteWithKey();
        $url = 'https://affiliate.example/?t=' . str_repeat('a', 500 - 29);

        $template = $site->promotionEmailOrDefault();
        $template->update(['top_button_url' => $url]);

        $this->assertSame($url, $template->fresh()->top_button_url);
        $this->assertSame(500, strlen($url));
    }
}
