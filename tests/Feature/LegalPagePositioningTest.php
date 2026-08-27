<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Services\CmsPageService;
use App\Support\LegalPageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The eleven standard legal pages are generated from ONE template for every
 * site, so their meta descriptions differed only by the brand name — the same
 * string on four domains, which is cross-site duplicate content on exactly the
 * pages a search engine is most likely to compare.
 *
 * `sites.positioning` is what varies them. These tests hold the two properties
 * that make it safe:
 *
 *  - a site WITHOUT a positioning renders byte-for-byte what it rendered before,
 *    so the change can be deployed before anyone fills the field in;
 *  - a site WITH one gets a description no sibling shares.
 *
 * NO REAL EMAIL, NO REAL DATABASE — enforced by Tests\TestCase.
 */
class LegalPagePositioningTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @return array<string, string> slug => meta_description */
    private function descriptions(string $brand, string $domain, ?string $positioning = null): array
    {
        $out = [];
        foreach (LegalPageContent::forBrand($brand, $domain, $positioning) as $page) {
            $out[$page['slug']] = $page['meta_description'];
        }

        return $out;
    }

    // ── Backward compatibility ───────────────────────────────────────────────

    /** The deploy-safety property: no positioning means no change at all. */
    public function test_a_site_without_positioning_renders_the_previous_descriptions(): void
    {
        $withNull = $this->descriptions('Winpalack', 'winpalack.com', null);
        $withEmpty = $this->descriptions('Winpalack', 'winpalack.com', '');
        $withWhitespace = $this->descriptions('Winpalack', 'winpalack.com', '   ');

        $this->assertSame($withNull, $withEmpty);
        $this->assertSame($withNull, $withWhitespace);
        // And the wording is the untouched original, not a trimmed variant.
        $this->assertSame(
            'Learn who we are at Winpalack, how we independently review and rate online casinos, and our commitment to safe, responsible play.',
            $withNull['about'],
        );
    }

    // ── The duplicate this exists to remove ──────────────────────────────────

    public function test_two_brands_with_different_positioning_share_no_description(): void
    {
        $a = $this->descriptions('Winpalack', 'winpalack.com', 'Casinos that treat players fairly');
        $b = $this->descriptions('Roulettingo', 'roulettingo.com', 'Roulette and live table games');

        $this->assertNotEmpty($a);
        $this->assertSame(array_keys($a), array_keys($b));

        foreach ($a as $slug => $description) {
            $this->assertNotSame(
                $description,
                $b[$slug],
                "the '{$slug}' description is identical on both brands",
            );
        }
    }

    /** Every one of the eleven pages carries it — not just the ones we looked at. */
    public function test_every_standard_page_carries_the_positioning(): void
    {
        $pages = $this->descriptions('Winpalack', 'winpalack.com', 'Casinos that treat players fairly');

        $this->assertCount(count(LegalPageContent::slugs()), $pages);

        foreach ($pages as $slug => $description) {
            $this->assertStringContainsString(
                'Casinos that treat players fairly.',
                $description,
                "the '{$slug}' description is missing the positioning",
            );
        }
    }

    // ── Punctuation ──────────────────────────────────────────────────────────

    /** The admin types a fragment; the description must not run two sentences together. */
    public function test_an_unpunctuated_positioning_gains_a_full_stop(): void
    {
        $pages = $this->descriptions('Winpalack', 'winpalack.com', 'Reviewed by players');

        $this->assertStringEndsWith('Reviewed by players.', $pages['about']);
    }

    /** …and one that is already punctuated is left exactly as written. */
    public function test_an_already_punctuated_positioning_is_not_double_punctuated(): void
    {
        foreach (['Reviewed by players.', 'Reviewed by players!', 'Who reviews these?'] as $clause) {
            $pages = $this->descriptions('Winpalack', 'winpalack.com', $clause);

            $this->assertStringEndsWith($clause, $pages['about']);
            $this->assertStringNotContainsString('..', $pages['about']);
        }
    }

    // ── The seeding path actually uses it ────────────────────────────────────

    public function test_seeding_a_site_writes_its_own_positioning_into_the_pages(): void
    {
        [$site] = $this->siteWithKey();
        $site->update(['positioning' => 'Roulette and live table games']);

        app(CmsPageService::class)->seedDefaultsForSite($site->refresh());

        $about = CmsPage::query()
            ->where('site_id', $site->id)
            ->where('slug', 'about')
            ->firstOrFail();

        $this->assertStringContainsString('Roulette and live table games.', (string) $about->meta_description);
    }

    /** A site that never set one still seeds successfully, with the old wording. */
    public function test_seeding_a_site_without_positioning_still_works(): void
    {
        [$site] = $this->siteWithKey();

        $created = app(CmsPageService::class)->seedDefaultsForSite($site);

        $this->assertSame(count(LegalPageContent::slugs()), $created);

        $about = CmsPage::query()
            ->where('site_id', $site->id)
            ->where('slug', 'about')
            ->firstOrFail();

        $this->assertStringEndsWith('responsible play.', (string) $about->meta_description);
    }
}
