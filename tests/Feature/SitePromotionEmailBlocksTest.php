<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\SitePromotionEmail;
use App\Services\PromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The per-site promotion email: reversible blocks and the CTA's own link.
 *
 * Same guarantee as the post-verification template — hiding a block is a setting,
 * not a deletion, so its content survives and restoring is a toggle.
 *
 * NO REAL EMAIL, NO REAL DATABASE: mailables are rendered in-process, and
 * `Tests\TestCase` throws unless the connection is in-memory SQLite and rewrites
 * every mail transport to `array`.
 */
class SitePromotionEmailBlocksTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @return array{0: \App\Models\Site, 1: SitePromotionEmail} */
    private function template(array $attrs = []): array
    {
        [$site] = $this->siteWithKey();
        $template = $site->promotionEmailOrDefault();
        $template->update($attrs);

        return [$site, $template->refresh()];
    }

    private function render(\App\Models\Site $site, SitePromotionEmail $template): string
    {
        return app(PromotionEmailService::class)
            ->previewMail($site, $template, 'fan@example.com')
            ->render();
    }

    public function test_a_visible_block_renders(): void
    {
        [$site, $template] = $this->template([
            'heading'       => 'Welcome to Winpalack',
            'hidden_blocks' => [],
        ]);

        $this->assertStringContainsString('Welcome to Winpalack', $this->render($site, $template));
    }

    public function test_hiding_a_block_removes_it_but_keeps_its_text(): void
    {
        [$site, $template] = $this->template([
            'heading'       => 'Welcome to Winpalack',
            'hidden_blocks' => ['heading'],
        ]);

        $this->assertStringNotContainsString('Welcome to Winpalack', $this->render($site, $template));
        // The wording survived being hidden — that is the whole point.
        $this->assertSame('Welcome to Winpalack', $template->heading);
    }

    public function test_every_optional_block_hides_and_restores(): void
    {
        [$site, $template] = $this->template();
        $all = SitePromotionEmail::OPTIONAL_BLOCKS;
        $before = $template->only($all);

        $template->update(['hidden_blocks' => $all]);
        $template->refresh();

        foreach ($template->visibleBlocks() as $block => $visible) {
            $this->assertFalse($visible, "{$block} should be hidden");
        }
        $this->assertSame($before, $template->only($all), 'content must survive hiding');

        $template->update(['hidden_blocks' => []]);
        $template->refresh();

        foreach ($template->visibleBlocks() as $block => $visible) {
            $this->assertTrue($visible, "{$block} should be visible again");
        }
        $this->assertSame($before, $template->only($all));

        // Still renders after the round trip.
        $this->assertNotSame('', trim($this->render($site, $template)));
    }

    public function test_an_unknown_stored_key_cannot_blank_a_live_block(): void
    {
        [, $template] = $this->template(['hidden_blocks' => ['a_field_that_no_longer_exists']]);

        $this->assertNotContains(false, $template->visibleBlocks());
    }

    public function test_the_cta_uses_its_own_link_when_set(): void
    {
        [$site, $template] = $this->template([
            'top_button_text' => 'View Details',
            'cta_button_url'  => 'https://offer.example.com/landing?c=abc',
            'hero_url'        => 'https://banner.example.com',
        ]);

        $this->assertStringContainsString('https://offer.example.com/landing?c=abc', $this->render($site, $template));
    }

    public function test_an_empty_cta_link_falls_back_to_the_offer_link(): void
    {
        // Compatibility guarantee: existing rows have no cta_button_url and must
        // keep pointing where they point today.
        [$site, $template] = $this->template([
            'top_button_text' => 'View Details',
            'cta_button_url'  => null,
            'hero_url'        => 'https://banner.example.com/offer',
        ]);

        $this->assertStringContainsString('https://banner.example.com/offer', $this->render($site, $template));
    }

    public function test_hiding_the_offer_link_does_not_leave_a_dead_button_href(): void
    {
        [$site, $template] = $this->template([
            'top_button_text' => 'View Details',
            'cta_button_url'  => null,
            'hero_url'        => 'https://banner.example.com/offer',
            'hidden_blocks'   => ['hero_url'],
        ]);

        $this->assertStringNotContainsString('https://banner.example.com/offer', $this->render($site, $template));
    }

    public function test_the_admin_round_trip_preserves_content(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->promotionEmailOrDefault();

        $base = fn (array $extra): array => [
            ...$template->only(['from_name', 'from_email', 'subject', 'unsubscribe_label', 'button_color', 'accent_color']),
            'active'  => true,
            'heading' => 'Welcome to Winpalack',
            ...$extra,
        ];

        $this->putJson("/api/v1/admin/sites/{$site->id}/promotion-email", $base([
            'hidden_blocks' => ['heading'],
        ]))->assertOk()
            ->assertJsonPath('data.hidden_blocks', ['heading'])
            ->assertJsonPath('data.heading', 'Welcome to Winpalack');

        $this->putJson("/api/v1/admin/sites/{$site->id}/promotion-email", $base([
            'hidden_blocks' => [],
        ]))->assertOk()
            ->assertJsonPath('data.hidden_blocks', [])
            ->assertJsonPath('data.heading', 'Welcome to Winpalack');
    }

    public function test_other_templates_did_not_gain_the_list(): void
    {
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'x@example.com']);

        foreach ([$site->emailTemplateOrDefault(), $site->verifyEmailOrDefault()] as $other) {
            $this->assertFalse(
                $other->isFillable('hidden_blocks'),
                $other::class . ' must not gain the optional-block list',
            );
        }
    }
}
