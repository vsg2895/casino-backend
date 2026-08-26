<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\VerificationPromotionEmail;
use App\Services\PostVerificationPromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Sizing and the optional background panel on the intro paragraph.
 *
 * The property worth pinning is that BOTH settings default to "as before": an
 * existing row must keep its 16px paragraph with no wrapper, because a styling
 * feature that silently restyles every deployed template is worse than no
 * feature. Only an explicit value changes anything.
 *
 * NO REAL EMAIL, NO REAL DATABASE — enforced by Tests\TestCase.
 */
class PromotionIntroStylingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function render(array $attrs): string
    {
        [$site] = $this->siteWithKey();
        $config = VerificationPromotionEmail::current();
        $config->update([...$attrs, 'hidden_blocks' => []]);

        return app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, $config->refresh(), 'fan@example.com')
            ->render();
    }

    // ── Defaults: nothing changes for an existing template ───────────────────

    public function test_unset_styling_renders_exactly_as_before(): void
    {
        $html = $this->render([
            'intro_text'                  => 'No deposit needed.',
            'intro_text_font_size'        => null,
            'intro_text_background_color' => null,
        ]);

        $this->assertStringContainsString('No deposit needed.', $html);
        $this->assertStringContainsString('font-size:16px', $html, 'falls back to the layout size');
    }

    public function test_no_panel_is_emitted_without_a_background(): void
    {
        // Differential, not absolute: the responsible-gambling notice uses the
        // same padded-panel CSS, so asserting the shape is absent would fail for
        // the wrong reason. Setting a background must add exactly one panel.
        $without = $this->render([
            'intro_text'                  => 'Plain intro line.',
            'intro_text_background_color' => null,
        ]);

        $with = $this->render([
            'intro_text'                  => 'Plain intro line.',
            'intro_text_background_color' => '#f3f4f6',
        ]);

        $this->assertSame(
            substr_count($without, 'padding:16px 20px') + 1,
            substr_count($with, 'padding:16px 20px'),
            'a background must add exactly one panel, and none without it',
        );

        $this->assertStringNotContainsString('#f3f4f6', $without);
    }

    // ── Font size ────────────────────────────────────────────────────────────

    public function test_a_custom_size_is_applied(): void
    {
        $html = $this->render([
            'intro_text'           => 'Bigger intro.',
            'intro_text_font_size' => 22,
        ]);

        $this->assertStringContainsString('font-size:22px', $html);
    }

    public function test_the_size_is_bounded(): void
    {
        $this->actingAsAdmin();
        $config = VerificationPromotionEmail::current();

        $base = fn (array $extra): array => [
            ...$config->only([
                'from_name', 'from_email', 'subject', 'unsubscribe_label',
                'button_color', 'accent_color', 'provider',
            ]),
            'active'        => false,
            'delay_minutes' => 60,
            ...$extra,
        ];

        foreach ([VerificationPromotionEmail::INTRO_TEXT_MIN_SIZE - 1,
                  VerificationPromotionEmail::INTRO_TEXT_MAX_SIZE + 1] as $invalid) {
            $this->putJson('/api/v1/admin/verification-promotion', $base([
                'intro_text_font_size' => $invalid,
            ]))->assertStatus(422)->assertJsonValidationErrors('intro_text_font_size');
        }

        foreach ([VerificationPromotionEmail::INTRO_TEXT_MIN_SIZE,
                  VerificationPromotionEmail::INTRO_TEXT_MAX_SIZE] as $valid) {
            $this->putJson('/api/v1/admin/verification-promotion', $base([
                'intro_text_font_size' => $valid,
            ]))->assertOk()->assertJsonPath('intro_text_font_size', $valid);
        }
    }

    // ── Background panel ─────────────────────────────────────────────────────

    public function test_a_background_colour_produces_a_panel(): void
    {
        $html = $this->render([
            'intro_text'                  => 'Panelled intro.',
            'intro_text_background_color' => '#f3f4f6',
        ]);

        $this->assertStringContainsString('background-color:#f3f4f6', $html);
        $this->assertStringContainsString('Panelled intro.', $html);
        // Same padded, rounded treatment as the responsible-gambling notice.
        $this->assertStringContainsString('padding:16px 20px', $html);
    }

    public function test_the_background_must_be_a_hex_colour(): void
    {
        $this->actingAsAdmin();
        $config = VerificationPromotionEmail::current();

        $this->putJson('/api/v1/admin/verification-promotion', [
            ...$config->only([
                'from_name', 'from_email', 'subject', 'unsubscribe_label',
                'button_color', 'accent_color', 'provider',
            ]),
            'active'                      => false,
            'delay_minutes'               => 60,
            'intro_text_background_color' => 'not-a-colour',
        ])->assertStatus(422)->assertJsonValidationErrors('intro_text_background_color');
    }

    public function test_size_and_panel_work_together(): void
    {
        $html = $this->render([
            'intro_text'                  => 'Both applied.',
            'intro_text_font_size'        => 20,
            'intro_text_background_color' => '#eeeeee',
        ]);

        $this->assertStringContainsString('font-size:20px', $html);
        $this->assertStringContainsString('background-color:#eeeeee', $html);
    }

    public function test_hiding_the_intro_removes_the_panel_too(): void
    {
        // The styling must not outlive the block it styles.
        [$site] = $this->siteWithKey();
        $config = VerificationPromotionEmail::current();
        $config->update([
            'intro_text'                  => 'Hidden intro.',
            'intro_text_background_color' => '#f3f4f6',
            'hidden_blocks'               => ['intro_text'],
        ]);

        $html = app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, $config->refresh(), 'fan@example.com')
            ->render();

        $this->assertStringNotContainsString('Hidden intro.', $html);
        $this->assertStringNotContainsString('background-color:#f3f4f6', $html);
    }

    public function test_the_admin_round_trip_persists_both_settings(): void
    {
        $this->actingAsAdmin();
        $config = VerificationPromotionEmail::current();

        $this->putJson('/api/v1/admin/verification-promotion', [
            ...$config->only([
                'from_name', 'from_email', 'subject', 'unsubscribe_label',
                'button_color', 'accent_color', 'provider',
            ]),
            'active'                      => false,
            'delay_minutes'               => 60,
            'intro_text_font_size'        => 18,
            'intro_text_background_color' => '#f3f4f6',
        ])->assertOk()
            ->assertJsonPath('intro_text_font_size', 18)
            ->assertJsonPath('intro_text_background_color', '#f3f4f6');
    }
}
