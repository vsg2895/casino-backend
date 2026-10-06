<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * When a news post's publish date is written, and when it is left alone.
 *
 * The rule under test: a NEWS post's date is when THIS site published it, not
 * when the wire service did. An ingested row keeps the source's date while it
 * is a draft — the chronology has to be truthful if an editor approves it — and
 * is restamped the moment it goes live.
 *
 * `news:restamp` is the one-off repair for everything that went live before
 * that rule existed, and its whole contract is that it moves the dates WITHOUT
 * reordering the feed.
 */
class NewsPublishDateTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function site(): Site
    {
        [$site] = $this->siteWithKey(['news_enabled' => true]);

        return $site;
    }

    /** @param array<string, mixed> $attrs */
    private function newsPost(Site $site, string $title, array $attrs = []): Article
    {
        return Article::create([
            'site_id' => $site->id,
            'type'    => Article::TYPE_NEWS,
            'title'   => $title,
            'slug'    => str($title)->slug()->value(),
            'active'  => false,
            'published_at' => Carbon::parse('2026-09-23 08:00:00'),
            ...$attrs,
        ]);
    }

    public function test_approving_a_scraped_post_restamps_it_to_now(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();
        // Collected twelve days ago, with the wire service's own date.
        $post = $this->newsPost($site, 'Wire story');

        $this->assertSame('2026-09-23 08:00:00', $post->published_at->toDateTimeString());

        $post->update(['active' => true]);

        $this->assertSame('2026-10-05 12:00:00', $post->fresh()->published_at->toDateTimeString());
    }

    public function test_a_date_set_in_the_same_save_wins(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();
        $post = $this->newsPost($site, 'Dated by hand');

        // Typing a date and switching the post on is a statement about when it
        // was published. The clock does not get to overrule the person.
        $post->update(['active' => true, 'published_at' => Carbon::parse('2026-10-01 09:30:00')]);

        $this->assertSame('2026-10-01 09:30:00', $post->fresh()->published_at->toDateTimeString());
    }

    public function test_a_scheduled_future_date_survives_activation(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();
        $post = $this->newsPost($site, 'Embargoed', ['published_at' => Carbon::parse('2026-10-09 07:00:00')]);

        $post->update(['active' => true]);

        // Stamping now() here would publish it four days early and make the
        // scheduling feature a lie.
        $this->assertSame('2026-10-09 07:00:00', $post->fresh()->published_at->toDateTimeString());
        $this->assertSame(0, Article::query()->visible()->count());
    }

    public function test_resaving_a_live_post_leaves_its_date_alone(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();
        $post = $this->newsPost($site, 'Already live');
        $post->update(['active' => true]);

        Carbon::setTestNow('2026-10-06 15:00:00');
        $post->update(['title' => 'Already live, retitled']);

        $this->assertSame('2026-10-05 12:00:00', $post->fresh()->published_at->toDateTimeString());
    }

    public function test_a_guide_is_never_restamped(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();
        $guide = Article::create([
            'site_id' => $site->id,
            'type'    => Article::TYPE_GUIDE,
            'title'   => 'How payouts work',
            'slug'    => 'how-payouts-work',
            'active'  => false,
            'published_at' => Carbon::parse('2026-01-04 10:00:00'),
        ]);

        $guide->update(['active' => true]);

        $this->assertSame('2026-01-04 10:00:00', $guide->fresh()->published_at->toDateTimeString());
    }

    public function test_restamp_moves_live_posts_to_yesterday_and_keeps_their_order(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();

        $oldest = $this->newsPost($site, 'Oldest', ['active' => true, 'published_at' => Carbon::parse('2026-09-20 08:00:00')]);
        $middle = $this->newsPost($site, 'Middle', ['active' => true, 'published_at' => Carbon::parse('2026-09-21 08:00:00')]);
        $newest = $this->newsPost($site, 'Newest', ['active' => true, 'published_at' => Carbon::parse('2026-09-22 08:00:00')]);

        $this->artisan('news:restamp')->assertSuccessful();

        $this->assertSame('2026-10-04 12:00:00', $newest->fresh()->published_at->toDateTimeString());
        $this->assertSame('2026-10-04 11:59:59', $middle->fresh()->published_at->toDateTimeString());
        $this->assertSame('2026-10-04 11:59:58', $oldest->fresh()->published_at->toDateTimeString());

        // The order the feed renders in is the order it was in before.
        $this->assertSame(
            ['Newest', 'Middle', 'Oldest'],
            Article::query()->ofType(Article::TYPE_NEWS)
                ->orderByDesc('published_at')->orderByDesc('id')->pluck('title')->all(),
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();
        $post = $this->newsPost($site, 'Untouched', ['active' => true]);

        $this->artisan('news:restamp --dry-run')->assertSuccessful();

        $this->assertSame('2026-09-23 08:00:00', $post->fresh()->published_at->toDateTimeString());
    }

    public function test_drafts_are_skipped_unless_asked_for(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();
        $draft = $this->newsPost($site, 'Not approved yet');

        $this->artisan('news:restamp')->assertSuccessful();
        $this->assertSame('2026-09-23 08:00:00', $draft->fresh()->published_at->toDateTimeString());

        $this->artisan('news:restamp --drafts')->assertSuccessful();
        $this->assertSame('2026-10-04 12:00:00', $draft->fresh()->published_at->toDateTimeString());
    }

    public function test_the_site_option_limits_the_run(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $mine = $this->site();
        $other = $this->site();

        $here = $this->newsPost($mine, 'Here', ['active' => true]);
        $there = $this->newsPost($other, 'There', ['active' => true]);

        $this->artisan('news:restamp --site=' . $mine->slug)->assertSuccessful();

        $this->assertSame('2026-10-04 12:00:00', $here->fresh()->published_at->toDateTimeString());
        $this->assertSame('2026-09-23 08:00:00', $there->fresh()->published_at->toDateTimeString());
    }

    public function test_the_days_option_chooses_how_far_back(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $site = $this->site();
        $post = $this->newsPost($site, 'Three days back', ['active' => true]);

        $this->artisan('news:restamp --days=3')->assertSuccessful();

        $this->assertSame('2026-10-02 12:00:00', $post->fresh()->published_at->toDateTimeString());
    }
}
