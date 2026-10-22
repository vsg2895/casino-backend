<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BonusCategory;
use App\Models\Casino;
use App\Models\SpecialOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The bonus diagnostic has to name the RIGHT failing condition.
 *
 * A funnel that always blames the last row is worse than no funnel: it sends
 * the operator to fix something that was never broken. Each test here breaks
 * exactly one of the seven conditions and asserts the command points at that
 * one.
 *
 * It must also stay READ-ONLY. It is meant to be run against production while
 * something is wrong, so a test pins that it changes no row.
 */
class DiagnoseBonusAreaTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A site with one casino, one active category and one visible offer —
     * the state every test below breaks in exactly one place.
     *
     * @return array{0: \App\Models\Site, 1: Casino, 2: BonusCategory, 3: SpecialOffer}
     */
    private function workingSetup(): array
    {
        [$site] = $this->siteWithKey();
        $site->forceFill(['bonus_enabled' => true])->save();

        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($site->id, ['active' => true, 'affiliate_url' => 'https://example.com/go']);

        $category = BonusCategory::create([
            'name'     => 'Free Spins',
            'slug'     => 'free-spins',
            'active'   => true,
            'position' => 1,
        ]);

        $offer = SpecialOffer::create([
            'casino_id'         => $casino->id,
            'title'             => 'Fifty Free Spins',
            'rating'            => 5,
            'active'            => true,
            'bonus_category_id' => $category->id,
        ]);

        return [$site, $casino, $category, $offer];
    }

    /**
     * Run the command and return what it PRINTED.
     *
     * `Artisan::call()`, not `$this->artisan()`: the latter asserts against its
     * own expectation buffer and leaves `Artisan::output()` empty, so every
     * assertion on the text silently compared against ''. The whole point here
     * is the wording the operator reads, so the output has to be real.
     */
    private function diagnose(string $slug): string
    {
        $status = Artisan::call('bonus:diagnose', ['site' => [$slug]]);

        $this->assertSame(0, $status, 'the diagnostic must not fail on a known site');

        return Artisan::output();
    }

    public function test_a_healthy_site_reports_a_chip(): void
    {
        [$site] = $this->workingSetup();

        $this->assertStringContainsString('CHIPS THIS SITE WILL RENDER: 1', $this->diagnose($site->slug));
    }

    /**
     * The condition that masks all the others: with the area switched off the
     * endpoint 404s, so no amount of correct data shows anything.
     */
    public function test_it_reports_the_disabled_flag_before_anything_else(): void
    {
        [$site] = $this->workingSetup();
        $site->forceFill(['bonus_enabled' => false])->save();

        $out = $this->diagnose($site->slug);

        $this->assertStringContainsString('bonus_enabled = false', $out);
        // And it does NOT go on to blame the data, which is perfectly fine.
        $this->assertStringNotContainsString('CHIPS THIS SITE WILL RENDER', $out);
    }

    public function test_it_blames_the_attachment_when_the_casino_is_detached(): void
    {
        [$site, $casino] = $this->workingSetup();
        $casino->sites()->updateExistingPivot($site->id, ['active' => false]);

        $out = $this->diagnose($site->slug);

        $this->assertStringContainsString('attachment active', $out);
        $this->assertStringContainsString('CHIPS THIS SITE WILL RENDER: 0', $out);
    }

    public function test_it_blames_the_casino_when_the_casino_is_inactive(): void
    {
        [$site, $casino] = $this->workingSetup();
        $casino->forceFill(['active' => false])->save();

        $this->assertStringContainsString('casino active', $this->diagnose($site->slug));
    }

    public function test_it_blames_expiry_when_the_offer_has_lapsed(): void
    {
        [$site, , , $offer] = $this->workingSetup();
        $offer->forceFill(['expires_at' => Carbon::now()->subDay()])->save();

        $out = $this->diagnose($site->slug);

        $this->assertStringContainsString('not expired', $out);
        $this->assertStringContainsString('CHIPS THIS SITE WILL RENDER: 0', $out);
    }

    /**
     * The most likely cause on a freshly built site: offers exist and are
     * visible, but nobody has filed them under a type yet.
     */
    public function test_it_blames_the_missing_category_link(): void
    {
        [$site, , , $offer] = $this->workingSetup();
        $offer->forceFill(['bonus_category_id' => null])->save();

        $out = $this->diagnose($site->slug);

        $this->assertStringContainsString('filed under a bonus category', $out);
        $this->assertStringContainsString('CHIPS THIS SITE WILL RENDER: 0', $out);
    }

    public function test_it_blames_the_category_when_the_category_is_switched_off(): void
    {
        [$site, , $category] = $this->workingSetup();
        $category->forceFill(['active' => false])->save();

        $out = $this->diagnose($site->slug);

        $this->assertStringContainsString('category is active', $out);
        $this->assertStringContainsString('CHIPS THIS SITE WILL RENDER: 0', $out);
    }

    /** It is run on production while something is broken. It must not write. */
    public function test_it_changes_nothing(): void
    {
        [$site, $casino, $category, $offer] = $this->workingSetup();

        $before = [
            'site'     => $site->fresh()->toArray(),
            'casino'   => $casino->fresh()->toArray(),
            'category' => $category->fresh()->toArray(),
            'offer'    => $offer->fresh()->toArray(),
        ];

        $this->diagnose($site->slug);

        $this->assertSame($before['site'], $site->fresh()->toArray());
        $this->assertSame($before['casino'], $casino->fresh()->toArray());
        $this->assertSame($before['category'], $category->fresh()->toArray());
        $this->assertSame($before['offer'], $offer->fresh()->toArray());
    }

    public function test_an_unknown_slug_fails_rather_than_reporting_nothing(): void
    {
        $this->assertNotSame(0, Artisan::call('bonus:diagnose', ['site' => ['no-such-site']]));
    }
}
