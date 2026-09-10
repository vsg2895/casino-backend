<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Services\PostVerificationPromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The Promotion After Verification email is SINGLE-BRAND.
 *
 * This replaces VerificationPromotionPreviewSiteTest, which guarded the editor's
 * "Preview as <site>" picker. That picker is gone, and so is the
 * `preview_site_id` column behind it: the template's {{site_name}},
 * {{site_url}}, {{site_domain}} and {{contact_email}} now come from
 * config('promotions.after_verification') and are the same for every recipient,
 * whichever of the six sites they confirmed on.
 *
 * What still has to hold, and is what these tests guard:
 *
 *  1. every site renders IDENTICAL branding;
 *  2. the unsubscribe link still resolves to the SUBSCRIBER'S OWN site, because
 *     the opt-out page lives on each domain and links already delivered point
 *     there;
 *  3. no site parameter is accepted anywhere, so no request can steer it.
 */
class VerificationPromotionBrandingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function render(Site $site): string
    {
        return app(PostVerificationPromotionEmailService::class)
            ->previewMail($site, VerificationPromotionEmail::current(), 'sub@example.com')
            ->render();
    }

    public function test_every_site_renders_the_same_fixed_branding(): void
    {
        $brand = config('promotions.after_verification');

        foreach ([['alpha', 'alpha.test'], ['beta', 'beta.test'], ['gamma', 'gamma.test']] as [$slug, $domain]) {
            [$site] = $this->siteWithKey(['slug' => $slug, 'domain' => $domain, 'name' => ucfirst($slug)]);

            $html = $this->render($site);

            $this->assertStringContainsString($brand['site_name'], $html);
            $this->assertStringContainsString($brand['contact_email'], $html);
            // The subscriber's OWN brand must not appear anywhere except the
            // unsubscribe link, which is asserted separately below.
            $this->assertStringNotContainsString(ucfirst($slug) . ' —', $html);
        }
    }

    public function test_the_unsubscribe_link_stays_on_the_subscribers_own_site(): void
    {
        // A slug with no entry in config/urls.php: Site::frontendBaseUrl()
        // prefers a configured URL over the domain column, so a real slug here
        // would assert against the production host instead of the fixture.
        [$site] = $this->siteWithKey(['slug' => 'probe-site', 'domain' => 'probe-site.test']);

        $html = $this->render($site);

        // The one place the subscriber's own domain is still correct: a shared
        // link would opt the wrong person out, and already-delivered links point
        // at their own domain.
        $this->assertMatchesRegularExpression('#https?://[^"]*probe-site\.test/unsubscribe/#', $html);
    }

    public function test_the_editor_no_longer_accepts_or_returns_a_site(): void
    {
        $this->actingAsAdmin();
        $config = VerificationPromotionEmail::current();

        $payload = [
            ...$config->only([
                'from_name', 'from_email', 'subject', 'unsubscribe_label',
                'button_color', 'accent_color', 'provider',
            ]),
            'active'        => false,
            'delay_minutes' => 60,
        ];

        $this->putJson('/api/v1/admin/verification-promotion', $payload)
            ->assertOk()
            // The picker is gone from the UI; the resource must not advertise it.
            ->assertJsonMissingPath('preview_site_id');

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('verification_promotion_emails', 'preview_site_id'),
            'the preview_site_id column should have been dropped',
        );
    }

    public function test_the_preview_needs_no_site_parameter(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey();
        $config = VerificationPromotionEmail::current();

        $payload = [
            ...$config->only([
                'from_name', 'from_email', 'subject', 'unsubscribe_label',
                'button_color', 'accent_color', 'provider',
            ]),
            'active'        => false,
            'delay_minutes' => 60,
        ];

        $response = $this->postJson('/api/v1/admin/verification-promotion/preview', $payload)->assertOk();

        $this->assertStringContainsString(
            config('promotions.after_verification.site_name'),
            $response->json('html'),
        );
    }

    public function test_the_real_send_uses_the_fixed_brand_not_the_subscribers_site(): void
    {
        [$site] = $this->siteWithKey(['slug' => 'probe-two', 'domain' => 'probe-two.test', 'name' => 'Probe Two']);

        $newsletter = Newsletter::create([
            'site_id' => $site->id, 'email' => 'x@example.test', 'full_name' => 'X',
        ]);

        $mail = app(PostVerificationPromotionEmailService::class)->mailFor(
            $site,
            VerificationPromotionEmail::current(),
            $newsletter->email,
            Newsletter::generateUnsubscribeToken(),
        );

        $brand = config('promotions.after_verification');

        $this->assertSame($brand['site_name'], $mail->siteName);
        $this->assertSame($brand['site_url'], $mail->siteUrl);
        $this->assertSame($brand['contact_email'], $mail->contactEmail);
        $this->assertStringContainsString('probe-two.test/unsubscribe/', $mail->unsubscribeUrl);
    }
}
