<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailSchedule;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionOverride;
use App\Models\VerificationPromotionSend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The exact bodies of the post-verification promotion's override and
 * "Send test" endpoints.
 *
 * NO REAL EMAIL LEAVES THIS FILE: the .env SendGrid mailer is pointed at the
 * `array` transport, or at a local transport that throws.
 */
class VerificationPromotionAdminContractTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string SECTION_FROM = 'offers@winpalack.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.public_mailer', 'sendgrid');
        config()->set('mail.mailers.sendgrid', ['transport' => 'array', 'key' => 'SG.test-key']);
    }

    private function configureSection(): VerificationPromotionEmail
    {
        $config = VerificationPromotionEmail::current();
        $config->update([
            'provider' => EmailSchedule::PROVIDER_SENDGRID_ENV,
            'sendgrid_key_id' => null,
            'mailgun_key_id' => null,
            'from_email' => self::SECTION_FROM,
            'from_name' => 'Winpalack',
            'footer_links' => [
                ['label' => 'Privacy Policy', 'url' => 'https://example.test/privacy'],
                ['label' => 'Terms', 'url' => 'https://example.test/terms'],
            ],
        ]);

        return $config->refresh();
    }

    private function sendTest(array $payload = [])
    {
        return $this->postJson('/api/v1/admin/verification-promotion/test', ['to' => 'admin@example.test', ...$payload]);
    }

    // ── Overrides ────────────────────────────────────────────────────────────

    public function test_overrides_list_every_site_by_name(): void
    {
        $this->actingAsAdmin();
        $this->configureSection();
        [$zulu] = $this->siteWithKey(['name' => 'Zulu', 'slug' => 'zulu', 'domain' => 'zulu.test']);
        [$alpha] = $this->siteWithKey(['name' => 'Alpha', 'slug' => 'alpha', 'domain' => 'alpha.test']);
        VerificationPromotionOverride::create([
            'site_id' => $zulu->id,
            'hero_url' => 'https://zulu.test/hero',
            'footer_link_urls' => [null, 'https://zulu.test/terms'],
        ]);

        $this->getJson('/api/v1/admin/verification-promotion/overrides')->assertOk()->assertExactJson([
            'footer_link_labels' => ['Privacy Policy', 'Terms'],
            'data' => [
                [
                    'site_id' => $alpha->id,
                    'site_name' => 'Alpha',
                    'site_slug' => 'alpha',
                    'domain' => 'alpha.test',
                    'active' => false,
                    'hero_image_url' => null,
                    'hero_url' => null,
                    'top_button_url' => null,
                    'cta_button_url' => null,
                    'email_preferences_url' => null,
                    'footer_link_urls' => [],
                ],
                [
                    'site_id' => $zulu->id,
                    'site_name' => 'Zulu',
                    'site_slug' => 'zulu',
                    'domain' => 'zulu.test',
                    'active' => true,
                    'hero_image_url' => null,
                    'hero_url' => 'https://zulu.test/hero',
                    'top_button_url' => null,
                    'cta_button_url' => null,
                    'email_preferences_url' => null,
                    'footer_link_urls' => [null, 'https://zulu.test/terms'],
                ],
            ],
        ]);
    }

    public function test_saving_an_override_keeps_footer_positions_and_absent_keys(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $url = "/api/v1/admin/verification-promotion/overrides/{$site->id}";

        $this->putJson($url, ['footer_link_urls' => ['https://a.test', ' ', 'https://c.test']])
            ->assertOk()
            ->assertExactJson(['data' => ['site_id' => $site->id, 'active' => false]]);

        $this->putJson($url, ['hero_url' => 'https://a.test/hero'])
            ->assertOk()
            ->assertExactJson(['data' => ['site_id' => $site->id, 'active' => true]]);

        $row = VerificationPromotionOverride::query()->where('site_id', $site->id)->sole();
        $this->assertSame(['https://a.test', null, 'https://c.test'], $row->footer_link_urls);
        $this->assertSame('https://a.test/hero', $row->hero_url);

        $this->putJson($url, ['hero_url' => null])
            ->assertOk()
            ->assertExactJson(['data' => ['site_id' => $site->id, 'active' => false]]);
    }

    // ── Preview ──────────────────────────────────────────────────────────────

    public function test_preview_without_any_site(): void
    {
        $this->actingAsAdmin();
        $config = $this->configureSection();

        $this->postJson('/api/v1/admin/verification-promotion/preview', array_filter($config->toArray(), static fn ($v): bool => $v !== null))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Register a site before previewing — the unsubscribe link is built from one.']);
    }

    // ── Send test ────────────────────────────────────────────────────────────

    public function test_send_test_without_any_site(): void
    {
        $this->actingAsAdmin();
        $this->configureSection();

        $this->sendTest()->assertStatus(422)->assertExactJson([
            'ok' => false,
            'message' => 'Register a site before sending a test — the unsubscribe link is built from one.',
        ]);
    }

    public function test_send_test_when_the_transport_is_unavailable(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey();
        $this->configureSection();
        config()->set('mail.mailers.sendgrid.key', '');

        $this->sendTest()->assertStatus(422)->assertExactJson([
            'ok' => false,
            'message' => 'Mail transport unavailable (.env SENDGRID_API_KEY): SENDGRID_API_KEY is not set in the '
                .'environment; cannot send with the .env SendGrid key.',
        ]);
        $this->assertSame(0, VerificationPromotionSend::query()->count());
    }

    public function test_send_test_when_the_transport_rejects_the_message(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->configureSection();
        Mail::extend('vp-failing', fn (array $config): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new RuntimeException('550 rejected');
            }

            public function __toString(): string
            {
                return 'vp-failing://';
            }
        });
        config()->set('mail.mailers.sendgrid', ['transport' => 'vp-failing', 'key' => 'SG.test-key']);

        $this->sendTest()->assertStatus(502)->assertExactJson([
            'ok' => false,
            'message' => 'Could not send test email via .env SENDGRID_API_KEY (SG.… 7faca904c387): 550 rejected',
        ]);

        $row = VerificationPromotionSend::query()->sole();
        $this->assertSame($site->id, $row->site_id);
        $this->assertSame(VerificationPromotionSend::STATUS_FAILED, $row->status);
        $this->assertTrue((bool) $row->is_test);
    }

    public function test_send_test_success(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey(['name' => 'First']);
        [$second] = $this->siteWithKey(['name' => 'Second']);
        $this->configureSection();

        $res = $this->sendTest(['site_id' => $second->id])->assertOk();

        $this->assertSame(['ok', 'message'], array_keys($res->json()));
        $this->assertTrue($res->json('ok'));
        // The SendGrid id is the transport's own random Message-ID.
        $this->assertMatchesRegularExpression(
            '/^Test email sent to admin@example\.test from offers@winpalack\.test via \.env SENDGRID_API_KEY '
                .'\(key SG\.… fingerprint 7faca904c387\) — SendGrid id [0-9a-f]{32}@winpalack\.test\.$/u',
            $res->json('message'),
        );

        $row = VerificationPromotionSend::query()->sole();
        $this->assertSame($second->id, $row->site_id);
        $this->assertSame(VerificationPromotionSend::STATUS_SUCCESS, $row->status);
    }
}
