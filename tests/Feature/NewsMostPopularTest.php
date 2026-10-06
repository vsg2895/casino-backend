<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The news page's "Most Popular" rail.
 *
 * It is driven by `to_be_most_popular`, its OWN flag. It used to reuse
 * `featured`, which meant the two surfaces could never be curated apart:
 * promoting a post to the home page strip silently put it in this rail, and
 * taking it out of the rail removed it from the home page.
 *
 * What ranks it is `most_popular_at`, the moment each post was picked; these
 * tests freeze the clock so every pick shares one instant and `published_at`,
 * the tiebreak, is what they measure.
 * {@see NewsMostPopularPickDateTest} covers the pick date itself.
 */
class NewsMostPopularTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // One instant for every pick in this class, so the rail's first sort
        // key ties and these tests go on measuring what they were written to
        // measure: the dates.
        Carbon::setTestNow('2026-10-06 09:00:00');
    }

    /** @return array{0: \App\Models\Site, 1: string} */
    private function newsSite(): array
    {
        [$site, $key] = $this->siteWithKey();
        $site->forceFill(['news_enabled' => true])->save();

        return [$site, $key];
    }

    private function newsPost(int $siteId, string $title, string $publishedAt, array $flags = []): Article
    {
        return Article::create([
            'site_id'      => $siteId,
            'type'         => Article::TYPE_NEWS,
            'title'        => $title,
            'slug'         => str($title)->slug()->value(),
            'active'       => true,
            'published_at' => Carbon::parse($publishedAt),
            'featured'     => $flags['featured'] ?? false,
            'to_be_most_popular' => $flags['popular'] ?? false,
        ]);
    }

    /** @return list<string> Titles in rail order. */
    private function rail($site, string $key): array
    {
        $res = $this->getJson($this->publicBase($site) . '/news', $this->siteHeaders($key))->assertOk();

        return array_column($res->json('data.popular'), 'title');
    }

    public function test_only_flagged_posts_appear_in_the_rail(): void
    {
        [$site, $key] = $this->newsSite();
        $this->newsPost($site->id, 'Picked', '2026-09-20 10:00:00', ['popular' => true]);
        $this->newsPost($site->id, 'Not picked', '2026-09-21 10:00:00');

        $this->assertSame(['Picked'], $this->rail($site, $key));
    }

    public function test_the_home_page_flag_no_longer_fills_the_rail(): void
    {
        // The separation this feature exists for: `featured` drives the home
        // page strip and must have no say here.
        [$site, $key] = $this->newsSite();
        $this->newsPost($site->id, 'Home strip only', '2026-09-20 10:00:00', ['featured' => true]);

        $this->assertSame([], $this->rail($site, $key));
    }

    public function test_the_rail_is_newest_first(): void
    {
        // Picked in the same frozen instant, so `most_popular_at` ties and the
        // publish date — the rail's second sort key — is what decides.
        [$site, $key] = $this->newsSite();
        $this->newsPost($site->id, 'Oldest', '2026-09-01 10:00:00', ['popular' => true]);
        $this->newsPost($site->id, 'Middle', '2026-09-10 10:00:00', ['popular' => true]);
        $this->newsPost($site->id, 'Newest', '2026-09-20 10:00:00', ['popular' => true]);

        $this->assertSame(['Newest', 'Middle', 'Oldest'], $this->rail($site, $key));
    }

    public function test_the_rail_holds_eight(): void
    {
        [$site, $key] = $this->newsSite();

        for ($i = 1; $i <= 12; $i++) {
            $this->newsPost($site->id, "Story {$i}", '2026-09-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' 10:00:00', ['popular' => true]);
        }

        $rail = $this->rail($site, $key);

        $this->assertCount(8, $rail);
        // The eight NEWEST of the twelve, not the first eight written.
        $this->assertSame('Story 12', $rail[0]);
        $this->assertSame('Story 5', $rail[7]);
    }

    public function test_a_hidden_or_unpublished_pick_never_reaches_the_rail(): void
    {
        [$site, $key] = $this->newsSite();
        $this->newsPost($site->id, 'Visible', '2026-09-20 10:00:00', ['popular' => true]);

        $hidden = $this->newsPost($site->id, 'Hidden', '2026-09-21 10:00:00', ['popular' => true]);
        $hidden->forceFill(['active' => false])->save();

        $draft = $this->newsPost($site->id, 'Draft', '2026-09-22 10:00:00', ['popular' => true]);
        $draft->forceFill(['published_at' => null])->save();

        $this->assertSame(['Visible'], $this->rail($site, $key));
    }
}
