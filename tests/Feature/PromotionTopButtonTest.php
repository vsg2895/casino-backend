<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\VerificationPromotionEmail;
use App\Services\PostVerificationPromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The post-verification promotion's TOP button.
 *
 * `top_button_text` was editable, validated, stored and returned by the API — but
 * the template never emitted it, so a label typed in the admin rendered nowhere
 * and the operator had no way to tell. These tests exist so that cannot recur:
 * a field the admin can fill must appear in the output.
 *
 * NO REAL EMAIL, NO REAL DATABASE — enforced by Tests\TestCase.
 */
class PromotionTopButtonTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function render(array $attrs): string
    {
        // The factory's own unique domain: this helper is called twice in a
        // single test, and a fixed domain collides on the unique index.
        [$site] = $this->siteWithKey();
        $config = VerificationPromotionEmail::current();
        $config->update($attrs);

        return app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, $config->refresh(), 'fan@example.com')
            ->render();
    }

    public function test_the_top_button_label_actually_renders(): void
    {
        $html = $this->render([
            'top_button_text' => 'Claim Now',
            'hidden_blocks'   => [],
        ]);

        $this->assertStringContainsString('Claim Now', $html);
    }

    public function test_the_top_button_uses_its_own_link_when_set(): void
    {
        $html = $this->render([
            'top_button_text' => 'Claim Now',
            'top_button_url'  => 'https://offer.example.com/top?c=1',
            'hero_url'        => 'https://banner.example.com',
            'hidden_blocks'   => [],
        ]);

        $this->assertStringContainsString('https://offer.example.com/top?c=1', $html);
    }

    public function test_an_empty_top_link_falls_back_to_the_banner_link(): void
    {
        // Compatibility: a row that never sets it points where the banner points.
        $html = $this->render([
            'top_button_text' => 'Claim Now',
            'top_button_url'  => null,
            'hero_url'        => 'https://banner.example.com/offer',
            'hidden_blocks'   => [],
        ]);

        $this->assertStringContainsString('https://banner.example.com/offer', $html);
    }

    public function test_clearing_the_label_hides_the_button_entirely(): void
    {
        $html = $this->render([
            'top_button_text' => null,
            'top_button_url'  => 'https://offer.example.com/top',
            'hidden_blocks'   => [],
        ]);

        $this->assertStringNotContainsString('https://offer.example.com/top', $html);
    }

    public function test_the_top_button_is_hideable_and_restorable(): void
    {
        // Same reversible mechanism as every other block: hiding keeps the text.
        $hidden = $this->render([
            'top_button_text' => 'Claim Now',
            'hidden_blocks'   => ['top_button_text'],
        ]);
        $this->assertStringNotContainsString('Claim Now', $hidden);
        $this->assertSame('Claim Now', VerificationPromotionEmail::current()->top_button_text);

        $restored = $this->render([
            'top_button_text' => 'Claim Now',
            'hidden_blocks'   => [],
        ]);
        $this->assertStringContainsString('Claim Now', $restored);
    }

    public function test_the_top_and_bottom_buttons_are_independent(): void
    {
        $html = $this->render([
            'top_button_text' => 'Top Action',
            'top_button_url'  => 'https://example.com/top',
            'cta_button_text' => 'Bottom Action',
            'cta_button_url'  => 'https://example.com/bottom',
            'hidden_blocks'   => [],
        ]);

        $this->assertStringContainsString('Top Action', $html);
        $this->assertStringContainsString('https://example.com/top', $html);
        $this->assertStringContainsString('Bottom Action', $html);
        $this->assertStringContainsString('https://example.com/bottom', $html);
    }

    public function test_the_top_button_renders_above_the_banner_and_the_cta_below(): void
    {
        // One action either side of the image, rather than two stacked under it.
        // Position is the whole point of this block, so it is asserted directly.
        $html = $this->render([
            'top_button_text' => 'Get Bonus',
            'cta_button_text' => 'Claim your offer',
            'hero_image_url'  => 'https://cdn.example.com/banner.jpg',
            'hidden_blocks'   => [],
        ]);

        $top    = strpos($html, 'Get Bonus');
        $banner = strpos($html, 'cdn.example.com/banner.jpg');
        $cta    = strpos($html, 'Claim your offer');

        $this->assertNotFalse($top, 'the top button must render');
        $this->assertNotFalse($banner, 'the banner must render');
        $this->assertNotFalse($cta, 'the CTA must render');

        $this->assertLessThan($banner, $top, 'the top button belongs ABOVE the banner');
        $this->assertGreaterThan($banner, $cta, 'the CTA belongs BELOW the banner');
    }

    public function test_the_admin_round_trip_persists_both_top_fields(): void
    {
        $this->actingAsAdmin();
        $config = VerificationPromotionEmail::current();

        $payload = [
            ...$config->only([
                'from_name', 'from_email', 'subject', 'unsubscribe_label',
                'button_color', 'accent_color', 'provider',
            ]),
            'active'          => false,
            'delay_minutes'   => 60,
            'top_button_text' => 'Claim Now',
            'top_button_url'  => 'https://offer.example.com/top',
        ];

        $this->putJson('/api/v1/admin/verification-promotion', $payload)
            ->assertOk()
            ->assertJsonPath('top_button_text', 'Claim Now')
            ->assertJsonPath('top_button_url', 'https://offer.example.com/top');
    }
}
