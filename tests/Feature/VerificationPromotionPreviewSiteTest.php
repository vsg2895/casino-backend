<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The site picker in the Promotion After Verification editor.
 *
 * It used to be preview-only: no column, not in $fillable, and the editor reset
 * it to the first registered site on every load — so choosing a site, saving and
 * reloading looked like the save had silently failed.
 *
 * The two halves that must both hold:
 *
 *  1. the choice PERSISTS, so the editor reopens on it;
 *  2. it stays a PREVIEW setting — the automatic send resolves each subscriber's
 *     own site and must never consult this column. That second half is the one
 *     worth guarding: a template that quietly rendered every subscriber's mail
 *     against one site would put the wrong brand in real inboxes.
 */
class VerificationPromotionPreviewSiteTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** The full editor payload, as the admin panel submits it. */
    private function payloadWith(array $overrides = []): array
    {
        $config = VerificationPromotionEmail::current();

        return [
            ...$config->only([
                'from_name', 'from_email', 'subject', 'unsubscribe_label',
                'button_color', 'accent_color', 'provider',
            ]),
            'active'        => false,
            'delay_minutes' => 60,
            ...$overrides,
        ];
    }

    public function test_the_chosen_preview_site_persists_across_a_reload(): void
    {
        $this->actingAsAdmin();
        [$first] = $this->siteWithKey(['name' => 'First Site']);
        [$chosen] = $this->siteWithKey(['name' => 'Chosen Site']);

        $this->putJson('/api/v1/admin/verification-promotion', $this->payloadWith([
            'preview_site_id' => $chosen->id,
        ]))->assertOk()->assertJsonPath('preview_site_id', $chosen->id);

        // The reload the admin panel performs — this is where the value used to
        // come back empty and the editor fell back to the first site.
        $this->getJson('/api/v1/admin/verification-promotion')
            ->assertOk()
            ->assertJsonPath('preview_site_id', $chosen->id);

        $this->assertNotSame($first->id, $chosen->id, 'the fixture must not pick the fallback site');
        $this->assertSame($chosen->id, VerificationPromotionEmail::current()->preview_site_id);
    }

    public function test_the_choice_can_be_cleared(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson('/api/v1/admin/verification-promotion', $this->payloadWith([
            'preview_site_id' => $site->id,
        ]))->assertOk();

        $this->putJson('/api/v1/admin/verification-promotion', $this->payloadWith([
            'preview_site_id' => null,
        ]))->assertOk()->assertJsonPath('preview_site_id', null);
    }

    public function test_the_preview_renders_against_the_saved_site(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey(['name' => 'First Site']);
        [$chosen] = $this->siteWithKey(['name' => 'Chosen Brand']);

        // {{site_name}} resolves against the picked site, not the fallback.
        $response = $this->postJson('/api/v1/admin/verification-promotion/preview', $this->payloadWith([
            'preview_site_id' => $chosen->id,
            'heading'         => 'Welcome to {{site_name}}',
        ]))->assertOk();

        $this->assertStringContainsString('Chosen Brand', $response->json('html'));
        $this->assertStringNotContainsString('First Site', $response->json('html'));
    }

    public function test_an_unknown_site_falls_back_instead_of_failing(): void
    {
        $this->actingAsAdmin();
        [$fallback] = $this->siteWithKey(['name' => 'Fallback Site']);

        // Validation rejects a non-existent id outright…
        $this->postJson('/api/v1/admin/verification-promotion/preview', $this->payloadWith([
            'preview_site_id' => 99999,
            'heading'         => '{{site_name}}',
        ]))->assertStatus(422)->assertJsonValidationErrors('preview_site_id');

        // …and no choice at all still renders, against a representative site.
        $response = $this->postJson('/api/v1/admin/verification-promotion/preview', $this->payloadWith([
            'preview_site_id' => null,
            'heading'         => '{{site_name}}',
        ]))->assertOk();

        $this->assertStringContainsString($fallback->name, $response->json('html'));
    }

    public function test_deleting_the_site_clears_the_reference_and_keeps_the_template(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->putJson('/api/v1/admin/verification-promotion', $this->payloadWith([
            'preview_site_id' => $site->id,
        ]))->assertOk();

        // nullOnDelete: removing a site must not take the global template with it.
        $site->forceDelete();

        $config = VerificationPromotionEmail::current();
        $this->assertNull($config->preview_site_id);
        $this->assertNotNull($config->from_email, 'the template itself must survive');
    }

    public function test_the_real_send_ignores_the_preview_site(): void
    {
        // The guard that matters. `preview_site_id` is an editor convenience; the
        // automatic promotion resolves the site from the SUBSCRIBER, so a
        // subscriber of site B must never be mailed site A's branding.
        $this->actingAsAdmin();
        [$previewSite] = $this->siteWithKey(['name' => 'Preview Only Site']);

        $this->putJson('/api/v1/admin/verification-promotion', $this->payloadWith([
            'preview_site_id' => $previewSite->id,
        ]))->assertOk();

        $config = VerificationPromotionEmail::current();

        // The column is populated…
        $this->assertSame($previewSite->id, $config->preview_site_id);

        // …and the send path never reads it. SendVerificationPromotionJob loads
        // `Newsletter::with('site')` and passes `$newsletter->site`; nothing in
        // the job or the mail service touches preview_site_id.
        $job = file_get_contents(app_path('Jobs/SendVerificationPromotionJob.php'));
        $service = file_get_contents(app_path('Services/PostVerificationPromotionEmailService.php'));

        $this->assertStringNotContainsString('preview_site_id', (string) $job);
        $this->assertStringNotContainsString('preview_site_id', (string) $service);
        $this->assertStringContainsString('$newsletter->site', (string) $job);
    }

    public function test_the_template_stays_global_with_many_sites_registered(): void
    {
        // Adding preview_site_id must not have turned this into a per-site
        // template: however many sites exist, current() resolves the same single
        // row, and the sites table gains no link back to it.
        $this->actingAsAdmin();
        $this->siteWithKey();
        $this->siteWithKey();
        $this->siteWithKey();

        $first = VerificationPromotionEmail::current();
        $second = VerificationPromotionEmail::current();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VerificationPromotionEmail::query()->count());
        $this->assertFalse(
            Site::query()->getModel()->isFillable('verification_promotion_email_id'),
            'sites must not gain a link to this template',
        );
    }
}
