<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `most_popular_at` — when each post was picked for the "Most Popular" rail.
 *
 * The rail is curated, so what ranks it is the moment an editor put each post
 * there. Publication date is a fact about the story, not about the pick, and on
 * a feed where a whole import shares one date it left the rail in an order
 * nobody chose.
 *
 * The column follows the flag: written when the flag goes on, cleared when it
 * goes off. That is what stops it claiming a pick date for a post that is not
 * picked, and what sends a re-promoted post back to the top.
 */
class NewsMostPopularPickDateTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function newsSite(): Site
    {
        [$site] = $this->siteWithKey(['news_enabled' => true]);

        return $site;
    }

    /** @param array<string, mixed> $attrs */
    private function newsPost(Site $site, string $title, string $publishedAt, array $attrs = []): Article
    {
        return Article::create([
            'site_id'      => $site->id,
            'type'         => Article::TYPE_NEWS,
            'title'        => $title,
            'slug'         => str($title)->slug()->value(),
            'active'       => true,
            'published_at' => Carbon::parse($publishedAt),
            ...$attrs,
        ]);
    }

    /** @return list<string> Titles in rail order, straight from the endpoint. */
    private function rail(Site $site, string $key): array
    {
        $res = $this->getJson($this->publicBase($site) . '/news', $this->siteHeaders($key))->assertOk();

        return array_column($res->json('data.popular'), 'title');
    }

    public function test_picking_a_post_records_when(): void
    {
        Carbon::setTestNow('2026-10-06 09:00:00');
        $site = $this->newsSite();
        $post = $this->newsPost($site, 'Story', '2026-09-23 10:00:00');

        $this->assertNull($post->most_popular_at);

        Carbon::setTestNow('2026-10-06 14:30:00');
        $post->update(['to_be_most_popular' => true]);

        $this->assertSame('2026-10-06 14:30:00', $post->fresh()->most_popular_at->toDateTimeString());
    }

    public function test_dropping_a_post_from_the_rail_clears_the_date(): void
    {
        Carbon::setTestNow('2026-10-06 09:00:00');
        $site = $this->newsSite();
        $post = $this->newsPost($site, 'Story', '2026-09-23 10:00:00', ['to_be_most_popular' => true]);

        $post->update(['to_be_most_popular' => false]);

        // A pick date on an unpicked post would be a claim about a state it is
        // not in — and would decide its rank if it were ever picked again.
        $this->assertNull($post->fresh()->most_popular_at);
    }

    public function test_an_unrelated_edit_leaves_the_pick_date_alone(): void
    {
        Carbon::setTestNow('2026-10-06 09:00:00');
        $site = $this->newsSite();
        $post = $this->newsPost($site, 'Story', '2026-09-23 10:00:00', ['to_be_most_popular' => true]);

        Carbon::setTestNow('2026-10-07 11:00:00');
        $post->update(['title' => 'Story, retitled']);

        $this->assertSame('2026-10-06 09:00:00', $post->fresh()->most_popular_at->toDateTimeString());
    }

    public function test_the_rail_leads_with_the_most_recently_picked(): void
    {
        [$site, $key] = $this->siteWithKey(['news_enabled' => true]);

        // Dates deliberately fight the picks: the OLDEST story is picked last.
        // Under the old ordering it would have sat at the bottom of the rail.
        Carbon::setTestNow('2026-10-06 09:00:00');
        $newest = $this->newsPost($site, 'Newest story', '2026-09-23 10:00:00');
        $oldest = $this->newsPost($site, 'Oldest story', '2026-09-01 10:00:00');

        Carbon::setTestNow('2026-10-06 10:00:00');
        $newest->update(['to_be_most_popular' => true]);

        Carbon::setTestNow('2026-10-06 12:00:00');
        $oldest->update(['to_be_most_popular' => true]);

        $this->assertSame(['Oldest story', 'Newest story'], $this->rail($site, $key));
    }

    public function test_re_picking_a_post_moves_it_back_to_the_top(): void
    {
        [$site, $key] = $this->siteWithKey(['news_enabled' => true]);

        Carbon::setTestNow('2026-10-06 09:00:00');
        $first = $this->newsPost($site, 'First pick', '2026-09-20 10:00:00', ['to_be_most_popular' => true]);

        Carbon::setTestNow('2026-10-06 10:00:00');
        $this->newsPost($site, 'Second pick', '2026-09-21 10:00:00', ['to_be_most_popular' => true]);

        $this->assertSame(['Second pick', 'First pick'], $this->rail($site, $key));

        Carbon::setTestNow('2026-10-06 11:00:00');
        $first->update(['to_be_most_popular' => false]);
        Carbon::setTestNow('2026-10-06 12:00:00');
        $first->update(['to_be_most_popular' => true]);

        $this->assertSame(['First pick', 'Second pick'], $this->rail($site, $key));
    }

    public function test_the_publish_date_still_breaks_a_tie(): void
    {
        [$site, $key] = $this->siteWithKey(['news_enabled' => true]);

        // Two posts picked in the same second — which is what a backfill, or an
        // editor ticking three boxes and saving once, actually produces.
        Carbon::setTestNow('2026-10-06 09:00:00');
        $this->newsPost($site, 'Older', '2026-09-01 10:00:00', ['to_be_most_popular' => true]);
        $this->newsPost($site, 'Newer', '2026-09-20 10:00:00', ['to_be_most_popular' => true]);

        $this->assertSame(['Newer', 'Older'], $this->rail($site, $key));
    }

    public function test_the_backfill_keeps_todays_rail_in_its_current_order(): void
    {
        Carbon::setTestNow('2026-10-06 09:00:00');
        [$site, $key] = $this->siteWithKey(['news_enabled' => true]);

        $this->newsPost($site, 'Older', '2026-09-01 10:00:00', ['to_be_most_popular' => true]);
        $this->newsPost($site, 'Newer', '2026-09-20 10:00:00', ['to_be_most_popular' => true]);

        // The state the migration finds on a server that has been running: the
        // flag set, no pick date recorded. Then its statement, verbatim.
        DB::table('articles')->update(['most_popular_at' => null]);
        DB::table('articles')
            ->where('to_be_most_popular', true)
            ->whereNull('most_popular_at')
            ->update(['most_popular_at' => DB::raw('published_at')]);

        $this->assertSame(['Newer', 'Older'], $this->rail($site, $key));
    }
}
