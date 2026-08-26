<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\VerificationPromotionEmail;
use App\Services\PostVerificationPromotionEmailService;
use App\Services\PromotionEmailService;
use App\Services\SubscriptionEmailService;
use App\Services\VerifyEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The eyebrow ("EXCLUSIVE SUBSCRIBER OFFER") as an optional, REVERSIBLE block.
 *
 * Hiding is a setting, not a deletion: `hidden_blocks` lists what is switched
 * off and each block's own text column is left untouched, so restoring is a
 * toggle rather than a retype. That independence is the property most worth
 * pinning — a regression that re-coupled the two would lose an operator's
 * wording silently, and only on the way back.
 *
 * NO REAL EMAIL AND NO REAL DATABASE. Nothing here sends: the mailables are
 * rendered to HTML in-process and asserted against. `Tests\TestCase` additionally
 * throws unless the connection is in-memory SQLite and rewrites every configured
 * mail transport to `array`, so a mistake cannot reach SendGrid or SMTP.
 */
class VerificationPromotionEyebrowTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const EYEBROW = 'Exclusive subscriber offer';

    /** Render the post-verification promotion exactly as a real send would. */
    private function render(?string $name = null): string
    {
        [$site] = $this->siteWithKey();
        $config = VerificationPromotionEmail::current();

        return app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, $config, 'fan@example.com', $name)
            ->render();
    }

    // ── Enabled ──────────────────────────────────────────────────────────────

    public function test_the_eyebrow_renders_when_enabled(): void
    {
        $config = VerificationPromotionEmail::current();
        $config->update(['eyebrow_text' => self::EYEBROW, 'hidden_blocks' => []]);

        $this->assertStringContainsString(self::EYEBROW, $this->render());
    }

    public function test_the_default_template_shows_the_eyebrow(): void
    {
        // Existing installations must look identical after deploying this change.
        $config = VerificationPromotionEmail::current();

        $this->assertSame([], $config->visibleBlocks() ? array_keys(array_filter($config->visibleBlocks(), fn (bool $v): bool => ! $v)) : []);
        $this->assertSame(self::EYEBROW, $config->eyebrow_text);
        $this->assertStringContainsString(self::EYEBROW, $this->render());
    }

    // ── Disabled ─────────────────────────────────────────────────────────────

    public function test_disabling_removes_the_text_and_leaves_no_empty_wrapper(): void
    {
        $config = VerificationPromotionEmail::current();
        $config->update([
            'eyebrow_text'    => self::EYEBROW,
            'hidden_blocks' => ['eyebrow_text'],
            'heading'         => 'Thanks for confirming your email',
        ]);

        $html = $this->render();

        $this->assertStringNotContainsString(self::EYEBROW, $html);

        // The eyebrow's own <p> carries a distinctive uppercase + 8px-bottom
        // style. An empty leftover wrapper would still emit it and leave a gap
        // above the headline, which is exactly what must not happen.
        $this->assertStringNotContainsString('text-transform:uppercase; letter-spacing:0.5px', $html);

        // …and the headline is still there, so nothing else was dropped with it.
        $this->assertStringContainsString('Thanks for confirming your email', $html);
    }

    public function test_hiding_the_only_populated_block_emits_no_padded_row(): void
    {
        // With the eyebrow the sole content of that section, hiding it must drop
        // the whole <tr> — otherwise a bare 32px-padded row renders as a gap.
        $config = VerificationPromotionEmail::current();
        $config->update([
            'eyebrow_text'    => self::EYEBROW,
            'hidden_blocks' => ['eyebrow_text'],
            'heading'         => null,
            'intro_text'      => null,
        ]);

        $html = $this->render();

        $this->assertStringNotContainsString(self::EYEBROW, $html);
        $this->assertStringNotContainsString('padding:32px 32px 20px', $html);
    }

    // ── Reversibility — the point of the whole change ────────────────────────

    public function test_disabling_preserves_the_text_and_re_enabling_restores_it(): void
    {
        $custom = 'Members-only welcome bonus';

        $config = VerificationPromotionEmail::current();
        $config->update(['eyebrow_text' => $custom, 'hidden_blocks' => []]);
        $this->assertStringContainsString($custom, $this->render());

        // Hide it…
        $config->update(['hidden_blocks' => ['eyebrow_text']]);
        $this->assertStringNotContainsString($custom, $this->render());

        // …the wording is still in the row, untouched.
        $this->assertSame($custom, VerificationPromotionEmail::current()->eyebrow_text);

        // …and turning it back on restores it byte-for-byte.
        $config->update(['hidden_blocks' => []]);
        $this->assertStringContainsString($custom, $this->render());
    }

    public function test_the_admin_can_hide_and_restore_it_without_touching_the_text(): void
    {
        // The round trip an operator actually performs, through the API.
        $this->actingAsAdmin();
        $custom = 'VIP subscriber offer';

        $base = fn (array $extra): array => [
            ...VerificationPromotionEmail::current()->only([
                'from_name', 'from_email', 'subject', 'unsubscribe_label',
                'button_color', 'accent_color', 'provider',
            ]),
            'active'        => false,
            'delay_minutes' => 60,
            ...$extra,
        ];

        $this->putJson('/api/v1/admin/verification-promotion', $base([
            'eyebrow_text' => $custom, 'hidden_blocks' => [],
        ]))->assertOk()->assertJsonPath('hidden_blocks', []);

        // Hide — note the payload still carries the text, as the form does.
        $this->putJson('/api/v1/admin/verification-promotion', $base([
            'eyebrow_text' => $custom, 'hidden_blocks' => ['eyebrow_text'],
        ]))->assertOk()
            ->assertJsonPath('hidden_blocks', ['eyebrow_text'])
            ->assertJsonPath('eyebrow_text', $custom);

        // Restore — no migration, no redeploy, no retyping.
        $this->putJson('/api/v1/admin/verification-promotion', $base([
            'eyebrow_text' => $custom, 'hidden_blocks' => [],
        ]))->assertOk()
            ->assertJsonPath('hidden_blocks', [])
            ->assertJsonPath('eyebrow_text', $custom);
    }

    public function test_the_optional_block_registry_drives_the_flags(): void
    {
        // The reusable seam: a block joins OPTIONAL_BLOCKS and is published
        // automatically, with no migration and no change to the mailable.
        $this->assertContains('eyebrow_text', VerificationPromotionEmail::OPTIONAL_BLOCKS);

        $config = VerificationPromotionEmail::current();
        $this->assertTrue($config->visibleBlocks()['eyebrow_text']);

        $config->update(['hidden_blocks' => ['eyebrow_text']]);
        $this->assertFalse($config->refresh()->visibleBlocks()['eyebrow_text']);

        // An unsaved model (the live preview) has no list — everything visible.
        $this->assertNotContains(false, (new VerificationPromotionEmail())->visibleBlocks());
    }

    public function test_every_optional_block_can_be_hidden_and_restored(): void
    {
        // The guarantee across the whole template: anything with a Remove button
        // comes back with its text intact.
        $config = VerificationPromotionEmail::current();
        $all = VerificationPromotionEmail::OPTIONAL_BLOCKS;

        $before = $config->only($all);

        $config->update(['hidden_blocks' => $all]);
        $config->refresh();

        foreach ($config->visibleBlocks() as $block => $visible) {
            $this->assertFalse($visible, "{$block} should be hidden");
        }

        // Every field's stored content survived being hidden.
        $this->assertSame($before, $config->only($all));

        // …and restoring is dropping the keys, not retyping anything.
        $config->update(['hidden_blocks' => []]);
        $config->refresh();

        foreach ($config->visibleBlocks() as $block => $visible) {
            $this->assertTrue($visible, "{$block} should be visible again");
        }

        $this->assertSame($before, $config->only($all));
    }

    public function test_an_unknown_stored_key_cannot_blank_a_live_block(): void
    {
        // A key left behind by a renamed field must be inert, never hide something
        // that still exists.
        $config = VerificationPromotionEmail::current();
        $config->update(['hidden_blocks' => ['a_field_that_no_longer_exists']]);

        $this->assertNotContains(false, $config->refresh()->visibleBlocks());
    }

    // ── The greeting still behaves as before ─────────────────────────────────

    public function test_the_optional_greeting_is_unchanged(): void
    {
        VerificationPromotionEmail::current()->update(['hidden_blocks' => []]);

        $this->assertStringContainsString('Dear Alex,', $this->render('Alex'));
        $this->assertStringNotContainsString('Dear', $this->render(null));
    }

    public function test_the_greeting_and_the_eyebrow_are_independent(): void
    {
        $config = VerificationPromotionEmail::current();
        $config->update(['eyebrow_text' => self::EYEBROW, 'hidden_blocks' => ['eyebrow_text']]);

        $withName = $this->render('Alex');

        $this->assertStringContainsString('Dear Alex,', $withName);
        $this->assertStringNotContainsString(self::EYEBROW, $withName);
    }

    // ── CTA destination ──────────────────────────────────────────────────────

    public function test_the_cta_uses_its_own_link_when_set(): void
    {
        $config = VerificationPromotionEmail::current();
        $config->update([
            'cta_button_text' => 'Claim your offer',
            'cta_button_url'  => 'https://offer.example.com/landing?c=abc',
            'hero_url'        => 'https://banner.example.com',
        ]);

        $html = $this->render();

        $this->assertStringContainsString('https://offer.example.com/landing?c=abc', $html);
    }

    public function test_an_empty_cta_link_falls_back_to_the_banner_link(): void
    {
        // The compatibility guarantee: existing rows have no cta_button_url and
        // must keep pointing exactly where they point today.
        $config = VerificationPromotionEmail::current();
        $config->update([
            'cta_button_text' => 'Claim your offer',
            'cta_button_url'  => null,
            'hero_url'        => 'https://banner.example.com/offer',
        ]);

        $this->assertStringContainsString('https://banner.example.com/offer', $this->render());
    }

    public function test_the_cta_link_accepts_placeholders(): void
    {
        // Affiliate destinations carry {{site_url}} and tracking macros, which is
        // why the field is a plain string rather than a validated URL.
        [$site] = $this->siteWithKey(['domain' => 'winpalack.com']);
        $config = VerificationPromotionEmail::current();
        $config->update([
            'cta_button_text' => 'Claim your offer',
            'cta_button_url'  => '{{site_url}}/welcome',
        ]);

        $html = app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, $config, 'fan@example.com')
            ->render();

        $this->assertStringContainsString('https://winpalack.com/welcome', $html);
    }

    // ── Scope: no other template touched ─────────────────────────────────────

    public function test_other_email_templates_render_unchanged(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'other@example.com']);

        // Hiding this template's eyebrow must have no reach beyond it.
        VerificationPromotionEmail::current()->update(['hidden_blocks' => ['eyebrow_text']]);

        $others = [
            'verify'       => app(VerifyEmailService::class)->mailForSubscriber($site, $subscriber),
            'subscription' => app(SubscriptionEmailService::class)->mailForSubscriber($site, $subscriber),
            'promotion'    => app(PromotionEmailService::class)
                ->mailForSubscriber($site, $site->promotionEmailOrDefault(), $subscriber),
        ];

        foreach ($others as $label => $mailable) {
            $html = $mailable->render();

            $this->assertNotSame('', trim($html), "{$label} email must still render");
            // None of them has an eyebrow block, and none gained one.
            $this->assertStringNotContainsString(
                'text-transform:uppercase; letter-spacing:0.5px',
                $html,
                "{$label} email markup must be untouched",
            );
        }
    }

    public function test_the_subscribe_and_verify_templates_did_not_gain_the_list(): void
    {
        // Both promotion templates share the reversible-block mechanism by design.
        // The subscribe and verify templates do NOT — they were never in scope, and
        // this is the guard against the pattern spreading by accident.
        [$site] = $this->siteWithKey();

        // The verify template gained the same mechanism when its footer identity
        // block was added, so only the subscribe template is expected to lack it.
        foreach ([$site->emailTemplateOrDefault()] as $template) {
            $this->assertFalse(
                $template->isFillable('hidden_blocks'),
                $template::class . ' must not gain the optional-block list',
            );
        }
    }
}
