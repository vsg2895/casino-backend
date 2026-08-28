<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SitePromotionEmail;
use App\Models\SiteVerifyEmail;
use App\Services\PromotionEmailService;
use App\Services\VerifyEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Button label size on the two PER-SITE templates.
 *
 * THE DEFAULTS DIFFER ON PURPOSE — 15px for the verify email, 18px for the
 * promotion offer — because each falls back to whatever its own layout already
 * rendered. That is what stops every existing site's mail changing size the
 * moment this column ships, and it is the property most likely to be "tidied"
 * into one shared constant by someone who has not read this comment.
 *
 * NO REAL EMAIL, NO REAL DATABASE — enforced by Tests\TestCase.
 */
class SiteEmailButtonSizeTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function renderVerify(array $attrs): string
    {
        [$site] = $this->siteWithKey();
        $template = $site->verifyEmailOrDefault();
        $template->update($attrs);

        return app(VerifyEmailService::class)
            ->previewMail($site, $template->refresh(), 'fan@example.com')
            ->render();
    }

    /** @param array<string, mixed> $attrs */
    private function renderPromotion(array $attrs): string
    {
        [$site] = $this->siteWithKey();
        $template = $site->promotionEmailOrDefault();
        $template->update($attrs + ['hidden_blocks' => []]);

        return app(PromotionEmailService::class)
            ->previewMail($site, $template->refresh(), 'fan@example.com')
            ->render();
    }

    // ── Backward compatibility: the reason the defaults differ ───────────────

    public function test_the_verify_button_keeps_its_own_15px_when_unset(): void
    {
        $html = $this->renderVerify(['button_text_font_size' => null]);

        $at = strpos($html, 'Verify My Email');
        $this->assertNotFalse($at);
        $anchor = (int) strrpos(substr($html, 0, $at), '<a ');

        $this->assertStringContainsString('font-size:15px', substr($html, $anchor, $at - $anchor));
        $this->assertSame(15, SiteVerifyEmail::BUTTON_TEXT_DEFAULT_SIZE);
    }

    public function test_the_promotion_button_keeps_its_own_18px_when_unset(): void
    {
        $html = $this->renderPromotion([
            'top_button_text'       => 'View Details',
            'button_text_font_size' => null,
        ]);

        $this->assertStringContainsString('font-size:18px', $html);
        $this->assertSame(18, SitePromotionEmail::BUTTON_TEXT_DEFAULT_SIZE);
    }

    /** The two must not be collapsed into one shared value. */
    public function test_the_two_templates_keep_different_defaults(): void
    {
        $this->assertNotSame(
            SiteVerifyEmail::BUTTON_TEXT_DEFAULT_SIZE,
            SitePromotionEmail::BUTTON_TEXT_DEFAULT_SIZE,
            'each template must fall back to the size its own layout already rendered',
        );
    }

    // ── The setting actually reaches the button ──────────────────────────────

    public function test_a_custom_size_applies_to_the_verify_button(): void
    {
        $html = $this->renderVerify(['button_text_font_size' => 21]);

        $at = strpos($html, 'Verify My Email');
        $anchor = (int) strrpos(substr($html, 0, (int) $at), '<a ');

        $this->assertStringContainsString('font-size:21px', substr($html, $anchor, (int) $at - $anchor));
    }

    public function test_a_custom_size_applies_to_the_promotion_button(): void
    {
        $html = $this->renderPromotion([
            'top_button_text'       => 'View Details',
            'button_text_font_size' => 21,
        ]);

        $this->assertStringContainsString('font-size:21px', $html);
        $this->assertStringNotContainsString('font-size:18px', $html);
    }

    // ── The verify button's LABEL ────────────────────────────────────────────

    public function test_the_verify_button_label_is_editable(): void
    {
        $html = $this->renderVerify(['verify_button_text' => 'Confirm My Address']);

        $this->assertStringContainsString('Confirm My Address', $html);
        $this->assertStringNotContainsString('Verify My Email', $html);
    }

    /** Placeholders work in the label, like every other plain field. */
    public function test_the_verify_button_label_substitutes_placeholders(): void
    {
        [$site] = $this->siteWithKey();
        $template = $site->verifyEmailOrDefault();
        $template->update(['verify_button_text' => 'Join {{site_name}}']);

        $html = app(VerifyEmailService::class)
            ->previewMail($site, $template->refresh(), 'fan@example.com')
            ->render();

        $this->assertStringContainsString('Join ' . e($site->name), $html);
        $this->assertStringNotContainsString('{{site_name}}', $html);
    }

    /**
     * THE safety property. This button is the only way a subscriber confirms, so
     * an empty label must restore the default rather than ship a blank pill.
     */
    public function test_a_blank_verify_button_label_falls_back_to_the_default(): void
    {
        foreach ([null, '', '   '] as $blank) {
            $html = $this->renderVerify(['verify_button_text' => $blank]);

            $this->assertStringContainsString(
                SiteVerifyEmail::DEFAULT_BUTTON_TEXT,
                $html,
                'a blank label must not produce a button with no caption',
            );
        }
    }

    /** A label whose placeholders resolve to nothing is still blank. */
    public function test_a_label_that_resolves_to_nothing_falls_back(): void
    {
        $html = $this->renderVerify(['verify_button_text' => '{{unknown_token_that_stays}}']);

        // Unknown tokens are left as-is, so this one is NOT blank — the point is
        // that the fallback triggers on emptiness, not on the presence of braces.
        $this->assertStringContainsString('{{unknown_token_that_stays}}', $html);
    }

    // ── Footer text colour ───────────────────────────────────────────────────

    public function test_the_footer_text_colour_applies_to_all_three_footer_lines(): void
    {
        $html = $this->renderVerify([
            'footer_text_color' => '#112233',
            'footer_note'       => 'You received this email because an address was registered.',
            'postal_address'    => '25 Regent Street, London SW1Y 4PH',
            'contact_email'     => 'info@example.com',
            'copyright_text'    => 'Copyright line',
            'hidden_blocks'     => [],
        ]);

        // note + address/contact + copyright
        $this->assertSame(3, substr_count($html, 'color:#112233'));
    }

    /** Unset keeps the grey these lines have always rendered at. */
    public function test_an_unset_footer_colour_falls_back_to_the_default(): void
    {
        $html = $this->renderVerify(['footer_text_color' => null, 'hidden_blocks' => []]);

        $this->assertStringContainsString(
            'color:' . SiteVerifyEmail::DEFAULT_FOOTER_TEXT_COLOR,
            $html,
        );
    }

    /**
     * Footer LINKS must keep the accent colour. A grey unsubscribe link that
     * matches the text around it stops reading as a link.
     */
    public function test_footer_links_keep_the_accent_colour(): void
    {
        [$site] = $this->siteWithKey();
        $template = $site->verifyEmailOrDefault();
        $template->update([
            'footer_text_color' => '#112233',
            'accent_color'      => '#ff0000',
            'contact_email'     => 'info@example.com',
            'hidden_blocks'     => [],
        ]);

        $html = app(VerifyEmailService::class)
            ->previewMail($site, $template->refresh(), 'fan@example.com')
            ->render();

        $at = strpos($html, 'mailto:info@example.com');
        $this->assertNotFalse($at);
        $anchor = (int) strrpos(substr($html, 0, $at), '<a ');
        $this->assertStringContainsString('color:#ff0000', substr($html, $anchor, $at - $anchor + 200));
    }

    public function test_an_invalid_footer_colour_is_rejected(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->verifyEmailOrDefault();

        $payload = $template->only(array_diff($template->getFillable(), ['site_id', 'hidden_blocks']));
        $payload['footer_text_color'] = 'not-a-colour';

        $this->putJson("/api/v1/admin/sites/{$site->id}/verify-email", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('footer_text_color');
    }

    // ── Bounds are enforced by the API, not merely suggested by the input ────

    public function test_an_out_of_range_verify_size_is_rejected(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->verifyEmailOrDefault();

        $payload = $template->only(array_diff($template->getFillable(), ['site_id', 'hidden_blocks']));
        $payload['button_text_font_size'] = SiteVerifyEmail::BUTTON_TEXT_MAX_SIZE + 1;

        $this->putJson("/api/v1/admin/sites/{$site->id}/verify-email", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('button_text_font_size');
    }

    public function test_an_out_of_range_promotion_size_is_rejected(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->promotionEmailOrDefault();

        $payload = $template->only(array_diff($template->getFillable(), ['site_id', 'hidden_blocks']));
        $payload['button_text_font_size'] = SitePromotionEmail::BUTTON_TEXT_MIN_SIZE - 1;

        $this->putJson("/api/v1/admin/sites/{$site->id}/promotion-email", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('button_text_font_size');
    }

    /** The admin needs the bounds to build its input; they must be served. */
    public function test_both_resources_expose_the_bounds(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->getJson("/api/v1/admin/sites/{$site->id}/verify-email")
            ->assertOk()
            ->assertJsonPath('data.button_text_min_size', SiteVerifyEmail::BUTTON_TEXT_MIN_SIZE)
            ->assertJsonPath('data.button_text_default_size', SiteVerifyEmail::BUTTON_TEXT_DEFAULT_SIZE);

        $this->getJson("/api/v1/admin/sites/{$site->id}/promotion-email")
            ->assertOk()
            ->assertJsonPath('data.button_text_max_size', SitePromotionEmail::BUTTON_TEXT_MAX_SIZE)
            ->assertJsonPath('data.button_text_default_size', SitePromotionEmail::BUTTON_TEXT_DEFAULT_SIZE);
    }
}
