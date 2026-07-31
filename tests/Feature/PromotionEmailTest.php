<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SitePromotionEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class PromotionEmailTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.sendgrid.from_domain', 'mail.test');
    }

    // ── Admin template management ─────────────────────────────────────────

    public function test_admin_show_auto_creates_default_promotion(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->getJson("/api/v1/admin/sites/{$site->id}/promotion-email")
            ->assertOk()
            ->assertJsonPath('data.site_id', $site->id)
            ->assertJsonPath('data.top_button_text', 'View Details')
            ->assertJsonPath('data.from_domain', 'mail.test');

        $this->assertDatabaseCount('site_promotion_emails', 1);
    }

    public function test_admin_can_update_promotion(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email",
            $this->validPayload(['heading' => 'Grab your bonus!']),
        )->assertOk()
            ->assertJsonPath('data.heading', 'Grab your bonus!');

        $this->assertDatabaseHas('site_promotion_emails', [
            'site_id' => $site->id,
            'heading' => 'Grab your bonus!',
        ]);
    }

    public function test_from_email_accepts_any_valid_address(): void
    {
        // The from-domain lock was removed (SMTP era); any valid address works.
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email",
            $this->validPayload(['from_email' => 'promo@some-other-domain.com']),
        )->assertOk()->assertJsonPath('data.from_email', 'promo@some-other-domain.com');
    }

    public function test_from_email_must_be_a_valid_email(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email",
            $this->validPayload(['from_email' => 'not-an-email']),
        )->assertStatus(422)->assertJsonValidationErrorFor('from_email');
    }

    public function test_button_color_must_be_hex(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email",
            $this->validPayload(['button_color' => 'green']),
        )->assertStatus(422)->assertJsonValidationErrorFor('button_color');
    }

    public function test_hero_image_url_is_optional(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email",
            $this->validPayload(['hero_image_url' => null]),
        )->assertOk()->assertJsonPath('data.hero_image_url', null);
    }

    // ── Removable elements: images + buttons ──────────────────────────────

    public function test_admin_can_remove_the_image_and_both_buttons(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email",
            $this->validPayload([
                'hero_image_url'  => null,
                'top_button_text' => null,
                'cta_button_text' => null,
                'hero_url'        => null,
            ]),
        )->assertOk()
            ->assertJsonPath('data.hero_image_url', null)
            ->assertJsonPath('data.top_button_text', null)
            ->assertJsonPath('data.cta_button_text', null);

        $this->assertDatabaseHas('site_promotion_emails', [
            'site_id'         => $site->id,
            'hero_image_url'  => null,
            'top_button_text' => null,
            'cta_button_text' => null,
        ]);
    }

    public function test_removed_elements_disappear_from_the_rendered_email(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // Baseline: everything present renders.
        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/preview",
            $this->validPayload(['top_button_text' => 'TOP CTA', 'cta_button_text' => 'BOTTOM CTA']),
        )->assertOk()->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'TOP CTA')
            && str_contains($html, 'BOTTOM CTA')
            && str_contains($html, 'cdn.example.com/hero.jpg'));

        // Cleared: the elements are gone from the markup entirely — no empty
        // button shell, no broken <img>.
        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/preview",
            $this->validPayload([
                'hero_image_url'  => null,
                'top_button_text' => null,
                'cta_button_text' => null,
            ]),
        )->assertOk()->assertJsonPath('html', fn (string $html): bool => ! str_contains($html, 'TOP CTA')
            && ! str_contains($html, 'BOTTOM CTA')
            && ! str_contains($html, 'cdn.example.com/hero.jpg')
            && ! str_contains($html, '<img'));
    }

    public function test_one_button_can_be_removed_while_the_other_stays(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/preview",
            $this->validPayload(['top_button_text' => null, 'cta_button_text' => 'STILL HERE']),
        )->assertOk()->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'STILL HERE'));
    }

    public function test_the_image_and_link_can_be_removed_while_buttons_remain(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // The exact combination that used to be rejected: image + offer link
        // cleared while both buttons still have labels. No field requires any
        // other, so this must save.
        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email",
            $this->validPayload([
                'hero_image_url'  => null,
                'hero_url'        => null,
                'top_button_text' => 'View Details',
                'cta_button_text' => 'Register Your Account',
            ]),
        )->assertOk()
            ->assertJsonPath('data.hero_url', null)
            ->assertJsonPath('data.top_button_text', 'View Details');
    }

    public function test_a_button_without_a_link_still_renders_unlinked(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/preview",
            $this->validPayload([
                'hero_url'        => null,
                'hero_image_url'  => null,
                'top_button_text' => 'UNLINKED BUTTON',
                'cta_button_text' => null,
            ]),
        )->assertOk()->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'UNLINKED BUTTON')
            // Rendered, but not as a link: no empty href, and the only anchor
            // left in the email is the mandatory unsubscribe link.
            && ! str_contains($html, 'href=""')
            && substr_count($html, '<a href') === 1
            && str_contains($html, 'Unsubscribe'));
    }

    public function test_every_content_block_is_independently_removable(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $blocks = [
            'preheader', 'hero_image_url', 'hero_url', 'top_button_text',
            'heading', 'intro_text', 'secondary_text', 'cta_button_text', 'disclaimer_text',
        ];

        // Each one on its own.
        foreach ($blocks as $block) {
            $this->putJson(
                "/api/v1/admin/sites/{$site->id}/promotion-email",
                $this->validPayload([$block => null]),
            )->assertOk()->assertJsonPath("data.{$block}", null);
        }

        // …and all of them at once: a valid email remains.
        $this->putJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email",
            $this->validPayload(array_fill_keys($blocks, null)),
        )->assertOk();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/preview",
            $this->validPayload(array_fill_keys($blocks, null)),
        )->assertOk()->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'Unsubscribe')
            && ! str_contains($html, '<img'));
    }

    public function test_structural_fields_stay_required(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // Sender, subject and the legally-required opt-out label are not
        // content blocks and must never be removable.
        foreach (['from_name', 'from_email', 'subject', 'unsubscribe_label'] as $field) {
            $this->putJson(
                "/api/v1/admin/sites/{$site->id}/promotion-email",
                $this->validPayload([$field => null]),
            )->assertStatus(422)->assertJsonValidationErrorFor($field);
        }
    }

    public function test_preview_renders_html_without_persisting(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/preview",
            $this->validPayload(['heading' => 'Totally Custom Heading']),
        )->assertOk()
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'Totally Custom Heading'));

        // Preview must not write.
        $this->assertDatabaseMissing('site_promotion_emails', ['heading' => 'Totally Custom Heading']);
    }

    public function test_preview_resolves_placeholders_and_bold(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['name' => 'Lucky Reels']);

        $this->postJson(
            "/api/v1/admin/sites/{$site->id}/promotion-email/preview",
            $this->validPayload([
                'heading'    => 'Welcome to {{site_name}}',
                'intro_text' => 'Claim **100 FS** now.',
            ]),
        )->assertOk()
            ->assertJsonPath('html', fn (string $html): bool => str_contains($html, 'Welcome to Lucky Reels')
                && str_contains($html, '<strong>100 FS</strong>'));
    }

    public function test_promotion_endpoints_require_auth(): void
    {
        [$site] = $this->siteWithKey();

        $this->getJson("/api/v1/admin/sites/{$site->id}/promotion-email")->assertUnauthorized();
    }

    public function test_defaults_factory_matches_fillable(): void
    {
        [$site] = $this->siteWithKey(['name' => 'Neon Palace']);
        $promo = SitePromotionEmail::create([
            'site_id' => $site->id,
            ...SitePromotionEmail::defaultsFor($site),
        ]);

        $this->assertSame('Neon Palace', $promo->from_name);
        $this->assertStringContainsString('{{site_name}}', $promo->subject);
        $this->assertTrue($promo->active);
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function validPayload(array $overrides = []): array
    {
        return [
            'from_name'         => 'Promo Sender',
            'from_email'        => 'offers@mail.test',
            'subject'           => 'A special offer from {{site_name}}',
            'preheader'         => 'Your welcome package is ready.',
            'hero_image_url'    => 'https://cdn.example.com/hero.jpg',
            'hero_url'          => '{{site_url}}',
            'top_button_text'   => 'View Details',
            'heading'           => 'Welcome to {{site_name}}',
            'intro_text'        => 'Get **100 FS** now.',
            'secondary_text'    => 'A trusted, licensed platform.',
            'cta_button_text'   => 'Register Your Account',
            'disclaimer_text'   => 'This is a one-time invitation.',
            'unsubscribe_label' => 'Unsubscribe',
            'button_color'      => '#75B636',
            'accent_color'      => '#f3a333',
            'active'            => true,
            ...$overrides,
        ];
    }
}
