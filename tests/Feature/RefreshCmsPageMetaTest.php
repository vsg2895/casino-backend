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
 * `cms:refresh-page-meta` exists because `seedDefaultsForSite()` is idempotent:
 * it never touches an existing page, so a change to the generator reaches new
 * sites only and the live domains keep the template description forever.
 *
 * The command therefore WRITES to pages a person may have edited, which makes
 * its two safety properties the whole point:
 *
 *  - it is a DRY RUN unless --force is passed;
 *  - it never overwrites a description that differs from the generated default,
 *    because a difference means a human wrote it.
 *
 * NO REAL EMAIL, NO REAL DATABASE — enforced by Tests\TestCase.
 */
class RefreshCmsPageMetaTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function seededSite(string $positioning): array
    {
        [$site] = $this->siteWithKey();
        // Seeded WITHOUT a positioning, exactly like the live sites were, then
        // given one afterwards — which is the migration path this command serves.
        app(CmsPageService::class)->seedDefaultsForSite($site);
        $site->update(['positioning' => $positioning]);

        return [$site->refresh(), $this->about($site->id)];
    }

    private function about(int $siteId): CmsPage
    {
        return CmsPage::query()->where('site_id', $siteId)->where('slug', 'about')->firstOrFail();
    }

    public function test_a_dry_run_reports_changes_but_writes_nothing(): void
    {
        [$site, $about] = $this->seededSite('Roulette and live table games');
        $before = $about->meta_description;

        $this->artisan('cms:refresh-page-meta', ['--site' => $site->slug])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();

        $this->assertSame($before, $this->about($site->id)->meta_description);
    }

    public function test_force_writes_the_positioning_into_every_standard_page(): void
    {
        [$site] = $this->seededSite('Roulette and live table games');

        $this->artisan('cms:refresh-page-meta', ['--site' => $site->slug, '--force' => true])
            ->assertSuccessful();

        $pages = CmsPage::query()
            ->where('site_id', $site->id)
            ->whereIn('slug', LegalPageContent::slugs())
            ->get();

        $this->assertCount(count(LegalPageContent::slugs()), $pages);
        foreach ($pages as $page) {
            $this->assertStringContainsString(
                'Roulette and live table games.',
                (string) $page->meta_description,
                "the '{$page->slug}' page was not refreshed",
            );
        }
    }

    /** THE safety property: a description someone wrote by hand is never touched. */
    public function test_a_hand_edited_description_survives_force(): void
    {
        [$site, $about] = $this->seededSite('Roulette and live table games');

        $handWritten = 'Our own carefully written description that nobody should clobber.';
        $about->update(['meta_description' => $handWritten]);

        $this->artisan('cms:refresh-page-meta', ['--site' => $site->slug, '--force' => true])
            ->assertSuccessful();

        $this->assertSame($handWritten, $this->about($site->id)->meta_description);

        // …while its untouched siblings on the same site DID get refreshed.
        $contact = CmsPage::query()->where('site_id', $site->id)->where('slug', 'contact')->firstOrFail();
        $this->assertStringContainsString('Roulette and live table games.', (string) $contact->meta_description);
    }

    /** Running it twice changes nothing the second time. */
    public function test_the_command_is_idempotent(): void
    {
        [$site] = $this->seededSite('Roulette and live table games');

        $this->artisan('cms:refresh-page-meta', ['--site' => $site->slug, '--force' => true])->assertSuccessful();
        $after = $this->about($site->id)->meta_description;

        $this->artisan('cms:refresh-page-meta', ['--site' => $site->slug, '--force' => true])
            ->expectsOutputToContain('already current')
            ->assertSuccessful();

        $this->assertSame($after, $this->about($site->id)->meta_description);
    }

    /** A site with no positioning is skipped rather than rewritten with itself. */
    public function test_a_site_without_positioning_is_skipped(): void
    {
        [$site] = $this->siteWithKey();
        app(CmsPageService::class)->seedDefaultsForSite($site);
        $before = $this->about($site->id)->meta_description;

        $this->artisan('cms:refresh-page-meta', ['--site' => $site->slug, '--force' => true])
            ->expectsOutputToContain('No positioning set')
            ->assertSuccessful();

        $this->assertSame($before, $this->about($site->id)->meta_description);
    }

    public function test_an_unknown_site_slug_fails(): void
    {
        $this->artisan('cms:refresh-page-meta', ['--site' => 'no-such-site'])->assertFailed();
    }
}
