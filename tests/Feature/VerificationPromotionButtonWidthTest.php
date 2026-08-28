<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\VerificationPromotionEmail;
use App\Services\PostVerificationPromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The two buttons in the post-verification promotion — one above the banner, one
 * below it — must be the SAME width whatever their labels say.
 *
 * Sized to their text they came out visibly different ("Get Bonus" against
 * "Claim My 100 Free Spins") and read as two unrelated controls rather than one
 * repeated call to action. A shrink-to-fit button is the default in email HTML,
 * so this is the kind of thing that silently comes back the next time the markup
 * is touched.
 *
 * NO REAL EMAIL, NO REAL DATABASE — enforced by Tests\TestCase.
 */
class VerificationPromotionButtonWidthTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function render(array $attrs): string
    {
        [$site] = $this->siteWithKey();
        $config = VerificationPromotionEmail::current();
        $config->update($attrs + ['hidden_blocks' => []]);

        return app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, $config->refresh(), 'fan@example.com')
            ->render();
    }

    /** The width declared on the cell that wraps $label. */
    private function widthOfButtonContaining(string $html, string $label): string
    {
        $at = strpos($html, $label);
        $this->assertNotFalse($at, "expected a button labelled '{$label}'");

        $cell = strrpos(substr($html, 0, $at), '<td');
        $this->assertNotFalse($cell);

        $markup = substr($html, $cell, $at - $cell);
        $this->assertMatchesRegularExpression('/width:\s*(\d+)px/', $markup, "no pixel width on the '{$label}' button");
        preg_match('/width:\s*(\d+)px/', $markup, $m);

        return $m[1];
    }

    public function test_both_buttons_are_the_same_width_with_very_different_labels(): void
    {
        $html = $this->render([
            'top_button_text' => 'Go',
            'cta_button_text' => 'Claim My 100 Free Spins Right Now',
        ]);

        $this->assertSame(
            $this->widthOfButtonContaining($html, 'Go'),
            $this->widthOfButtonContaining($html, 'Claim My 100 Free Spins Right Now'),
            'the two buttons must render at the same width regardless of label length',
        );
    }

    /**
     * `display:block` is what makes the anchor fill the fixed-width cell. Without
     * it the cell is wide but the clickable pill is only as wide as its text,
     * which looks right and behaves wrong.
     */
    public function test_each_button_anchor_fills_its_cell(): void
    {
        $html = $this->render([
            'top_button_text' => 'Get Bonus',
            'cta_button_text' => 'Claim My 100 Free Spins',
        ]);

        foreach (['Get Bonus', 'Claim My 100 Free Spins'] as $label) {
            $at = strpos($html, $label);
            $anchor = strrpos(substr($html, 0, $at), '<a ');
            $markup = substr($html, (int) $anchor, $at - (int) $anchor);

            $this->assertStringContainsString('display:block', $markup, "the '{$label}' anchor must fill its cell");
            $this->assertStringContainsString('text-align:center', $markup, "the '{$label}' label must stay centred");
        }
    }

    /** The width must be a real number, not an accident of the label. */
    public function test_the_button_width_survives_a_label_longer_than_the_button(): void
    {
        $long = 'Claim Your Enormous Exclusive Welcome Bonus Package Today';
        $html = $this->render(['top_button_text' => 'Go', 'cta_button_text' => $long]);

        $this->assertSame('280', $this->widthOfButtonContaining($html, $long));
    }

    // ── Label size ───────────────────────────────────────────────────────────

    /** One setting drives BOTH buttons, so they stay a matched pair. */
    public function test_a_custom_button_size_applies_to_both_buttons(): void
    {
        $html = $this->render([
            'top_button_text'       => 'Get Bonus',
            'cta_button_text'       => 'See My Offer',
            'button_text_font_size' => 22,
        ]);

        foreach (['Get Bonus', 'See My Offer'] as $label) {
            $at = strpos($html, $label);
            $anchor = (int) strrpos(substr($html, 0, $at), '<a ');
            $markup = substr($html, $anchor, $at - $anchor);

            $this->assertStringContainsString('font-size:22px', $markup, "the '{$label}' label ignored the size");
        }
    }

    /** Unset falls back to the layout's own 16px — an existing row is unchanged. */
    public function test_an_unset_button_size_falls_back_to_the_default(): void
    {
        $html = $this->render([
            'top_button_text'       => 'Get Bonus',
            'cta_button_text'       => 'See My Offer',
            'button_text_font_size' => null,
        ]);

        $at = strpos($html, 'See My Offer');
        $anchor = (int) strrpos(substr($html, 0, $at), '<a ');

        $this->assertStringContainsString(
            'font-size:' . VerificationPromotionEmail::BUTTON_TEXT_DEFAULT_SIZE . 'px',
            substr($html, $anchor, $at - $anchor),
        );
    }

    /** The bounds are enforced by the API, not just suggested by the input. */
    public function test_a_size_outside_the_bounds_is_rejected(): void
    {
        $this->actingAsAdmin();
        $config = VerificationPromotionEmail::current();

        $payload = $config->only(array_diff($config->getFillable(), ['hidden_blocks']));

        $this->putJson('/api/v1/admin/verification-promotion', [
            ...$payload,
            'button_text_font_size' => VerificationPromotionEmail::BUTTON_TEXT_MAX_SIZE + 1,
        ])->assertStatus(422)->assertJsonValidationErrors('button_text_font_size');
    }

    // ── The intro panel, which is what set the paragraph in from the heading ──

    /** No background colour: the plain paragraph, flush with the heading. */
    public function test_the_intro_has_no_wrapper_without_a_background_colour(): void
    {
        $html = $this->render([
            'heading'                     => 'Your 100 free spins are ready',
            'intro_text'                  => 'Your exclusive welcome offer is unlocked.',
            'intro_text_background_color' => null,
        ]);

        $at = strpos($html, 'Your exclusive welcome offer is unlocked.');
        $this->assertNotFalse($at);

        // The paragraph's own <p> should be the element immediately wrapping it —
        // no padded <td> introduced between the heading and the text.
        $before = substr($html, (int) strrpos(substr($html, 0, $at), '<p '), $at - (int) strrpos(substr($html, 0, $at), '<p '));
        $this->assertStringContainsString('margin:0', $before);
        $this->assertStringNotContainsString('padding', $before);
    }
}
