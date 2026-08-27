<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\VerificationPromotionEmail;
use App\Services\PostVerificationPromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The two green bands at the top of the post-verification promotion.
 *
 * The brand band and the confirmation strip are normally the same colour, so
 * they read as one block and the visual gap between their two lines is the sum
 * of the brand band's BOTTOM padding and the strip's TOP padding. Those inner
 * values are deliberately tight — which is correct while both render, and wrong
 * the moment one is removed: a band tuned to sit against a neighbour leaves its
 * text visibly off-centre once that neighbour is gone.
 *
 * Every block in this template is removable, so both bands must survive being
 * left on their own. That is what these tests hold.
 *
 * NO REAL EMAIL, NO REAL DATABASE — enforced by Tests\TestCase.
 */
class VerificationPromotionHeaderPaddingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function render(array $attrs): string
    {
        [$site] = $this->siteWithKey();
        $config = VerificationPromotionEmail::current();
        $config->update($attrs);

        return app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, $config->refresh(), 'fan@example.com')
            ->render();
    }

    /**
     * Pull the `padding` shorthand off the table cell that contains $needle.
     *
     * Deliberately anchored on the cell's own content rather than on a class or
     * an index: email HTML has neither, and matching by position would silently
     * follow the wrong cell the next time a row is added above.
     */
    private function paddingOfCellContaining(string $html, string $needle): string
    {
        $position = strpos($html, $needle);
        $this->assertNotFalse($position, "expected the email to contain '{$needle}'");

        $openingTag = strrpos(substr($html, 0, $position), '<td');
        $this->assertNotFalse($openingTag, "no <td> wraps '{$needle}'");

        $cell = substr($html, $openingTag, $position - $openingTag);
        $this->assertMatchesRegularExpression('/padding:\s*([^;"]+)/', $cell);
        preg_match('/padding:\s*([^;"]+)/', $cell, $matches);

        return trim($matches[1]);
    }

    /** @return array{top: string, bottom: string} */
    private function verticalPadding(string $shorthand): array
    {
        $parts = preg_split('/\s+/', $shorthand) ?: [];

        return match (count($parts)) {
            // "14px"            → all four sides
            1 => ['top' => $parts[0], 'bottom' => $parts[0]],
            // "14px 32px"       → vertical, horizontal
            2 => ['top' => $parts[0], 'bottom' => $parts[0]],
            // "14px 32px 3px"   → top, horizontal, bottom
            3 => ['top' => $parts[0], 'bottom' => $parts[2]],
            default => ['top' => $parts[0], 'bottom' => $parts[2]],
        };
    }

    // ── The brand band standing alone ────────────────────────────────────────

    /** The reported bug: removing the confirmation line left the brand high in its band. */
    public function test_the_brand_band_is_vertically_centred_without_the_confirmation_line(): void
    {
        $html = $this->render([
            'header_brand_text' => 'WinPalack',
            'confirmation_text' => 'Email confirmed',
            'hidden_blocks'     => ['confirmation_text'],
        ]);

        $this->assertStringNotContainsString('Email confirmed', $html, 'the strip must actually be hidden');

        $padding = $this->verticalPadding($this->paddingOfCellContaining($html, 'WinPalack'));

        $this->assertSame(
            $padding['top'],
            $padding['bottom'],
            'with no confirmation strip beneath it, the brand band must pad equally above and below.',
        );
    }

    /** Clearing the text (rather than hiding the block) must behave identically. */
    public function test_the_brand_band_is_centred_when_the_confirmation_text_is_cleared(): void
    {
        $html = $this->render([
            'header_brand_text' => 'WinPalack',
            'confirmation_text' => null,
            'hidden_blocks'     => [],
        ]);

        $padding = $this->verticalPadding($this->paddingOfCellContaining($html, 'WinPalack'));

        $this->assertSame($padding['top'], $padding['bottom']);
    }

    // ── The confirmation strip standing alone ────────────────────────────────

    public function test_the_confirmation_strip_is_centred_without_the_brand_band(): void
    {
        $html = $this->render([
            'header_brand_text' => 'WinPalack',
            'confirmation_text' => 'Email confirmed',
            'hidden_blocks'     => ['header_brand_text'],
        ]);

        // Asserted on the brand band's OWN markup, not on its text: the brand
        // name also reaches the CTA label ("Claim 100 Free Spins at …"), so a
        // search for the name finds it whether or not the band rendered.
        $this->assertStringNotContainsString(
            'text-decoration:none; text-transform:uppercase;',
            $html,
            'the brand band must actually be hidden',
        );

        $padding = $this->verticalPadding($this->paddingOfCellContaining($html, 'Email confirmed'));

        $this->assertSame(
            $padding['top'],
            $padding['bottom'],
            'with no brand band above it, the confirmation strip must pad equally above and below.',
        );
    }

    // ── Both together ────────────────────────────────────────────────────────

    /**
     * The tight inner values are the whole reason the header is not a slab, so
     * they must survive: together, the two facing edges stay smaller than the
     * outer ones.
     */
    public function test_the_two_bands_stay_tight_against_each_other_when_both_render(): void
    {
        $html = $this->render([
            'header_brand_text' => 'WinPalack',
            'confirmation_text' => 'Email confirmed',
            'hidden_blocks'     => [],
        ]);

        $brand = $this->verticalPadding($this->paddingOfCellContaining($html, 'WinPalack'));
        $strip = $this->verticalPadding($this->paddingOfCellContaining($html, 'Email confirmed'));

        $px = static fn (string $value): int => (int) $value;

        $this->assertLessThan(
            $px($brand['top']),
            $px($brand['bottom']),
            'the brand band should hug the strip below it more tightly than its own top edge.',
        );
        $this->assertLessThan(
            $px($strip['bottom']),
            $px($strip['top']),
            'the strip should hug the brand band above it more tightly than its own bottom edge.',
        );
    }
}
