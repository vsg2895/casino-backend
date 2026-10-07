<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SitePromotionEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The promotion-email backfill.
 *
 * A site attached before a field existed in `defaultsFor()` keeps NULL in it for
 * ever — the attach-time defaults run once. idevaffiliation was the visible
 * case: no banner, no banner link, no top button, no footer identity, so
 * importing its template into an Email config credential brought nothing across.
 *
 * The one rule worth testing hardest is the restraint: it fills GAPS and leaves
 * authored copy alone, which is what makes it safe to run on production twice.
 */
class PromotionEmailBackfillTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $this->artisan('db:seed', ['--class' => 'PromotionEmailBackfillSeeder', '--force' => true])
            ->assertSuccessful();
    }

    /**
     * The row as a site-registration would have written it.
     *
     * Created here rather than relying on the factory: the promotion email is
     * written by the attach flow, and a factory-made site legitimately has none
     * — which is the case the seeder skips.
     */
    private function promotionFor(Site $site): SitePromotionEmail
    {
        return SitePromotionEmail::firstOrCreate(
            ['site_id' => $site->id],
            SitePromotionEmail::defaultsFor($site),
        );
    }

    public function test_an_empty_field_is_filled_from_the_defaults(): void
    {
        [$site] = $this->siteWithKey();
        $promotion = $this->promotionFor($site);
        $promotion->forceFill([
            'hero_image_url' => null,
            'hero_url'       => null,
            'contact_email'  => '',
        ])->save();

        $this->runBackfill();

        $fresh = $promotion->fresh();
        $this->assertNotEmpty($fresh->hero_image_url);
        $this->assertNotEmpty($fresh->hero_url);
        $this->assertSame('info@' . $site->domain, $fresh->contact_email);
    }

    public function test_authored_copy_is_never_overwritten(): void
    {
        [$site] = $this->siteWithKey();
        $promotion = $this->promotionFor($site);
        $promotion->forceFill([
            'heading'        => 'Our own heading',
            'hero_image_url' => 'https://example.com/ours.jpg',
            'postal_address' => null,
        ])->save();

        $this->runBackfill();

        $fresh = $promotion->fresh();
        $this->assertSame('Our own heading', $fresh->heading);
        $this->assertSame('https://example.com/ours.jpg', $fresh->hero_image_url);
        // …and the one real gap still got filled.
        $this->assertNotEmpty($fresh->postal_address);
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        [$site] = $this->siteWithKey();
        $this->promotionFor($site)->forceFill(['hero_image_url' => null])->save();

        $this->runBackfill();
        $after = SitePromotionEmail::where('site_id', $site->id)->sole()->updated_at;

        $this->runBackfill();

        $this->assertEquals($after, SitePromotionEmail::where('site_id', $site->id)->sole()->updated_at);
    }

    public function test_decisions_are_left_alone(): void
    {
        [$site] = $this->siteWithKey();
        $promotion = $this->promotionFor($site);
        // `active` and the sender identity are choices an empty value can
        // legitimately represent, so the backfill must not "repair" them.
        $promotion->forceFill(['active' => false, 'from_email' => '', 'hero_url' => null])->save();

        $this->runBackfill();

        $fresh = $promotion->fresh();
        $this->assertFalse((bool) $fresh->active);
        $this->assertSame('', (string) $fresh->from_email);
        $this->assertNotEmpty($fresh->hero_url);
    }
}
