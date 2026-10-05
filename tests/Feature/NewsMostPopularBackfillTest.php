<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The backfill that carries existing "Most Popular" picks onto the new column.
 *
 * Splitting the rail off `featured` left `to_be_most_popular` false everywhere,
 * so without this the rail would have gone empty on deploy and an editor would
 * have had to re-choose posts that were already chosen.
 *
 * The migration itself has already run under RefreshDatabase, so these tests
 * exercise the same statement against rows created afterwards — which is also
 * what proves it is safe to run twice.
 */
class NewsMostPopularBackfillTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** The migration's statement, verbatim. */
    private function backfill(): void
    {
        DB::table('articles')
            ->where('type', 'news')
            ->where('featured', true)
            ->where('to_be_most_popular', false)
            ->update(['to_be_most_popular' => true]);
    }

    private function article(int $siteId, string $type, string $title, bool $featured): Article
    {
        return Article::create([
            'site_id'      => $siteId,
            'type'         => $type,
            'title'        => $title,
            'slug'         => str($title)->slug()->value(),
            'active'       => true,
            'published_at' => Carbon::parse('2026-09-20 10:00:00'),
            'featured'     => $featured,
        ]);
    }

    public function test_a_featured_news_post_becomes_a_rail_pick(): void
    {
        [$site] = $this->siteWithKey();
        $picked = $this->article($site->id, Article::TYPE_NEWS, 'Picked', true);
        $plain = $this->article($site->id, Article::TYPE_NEWS, 'Plain', false);

        $this->backfill();

        $this->assertTrue((bool) $picked->fresh()->to_be_most_popular);
        $this->assertFalse((bool) $plain->fresh()->to_be_most_popular, 'an unfeatured post is not promoted');
    }

    public function test_guides_are_left_alone(): void
    {
        // Guides have no rail; `featured` means nothing to them.
        [$site] = $this->siteWithKey();
        $guide = $this->article($site->id, Article::TYPE_GUIDE, 'Featured guide', true);

        $this->backfill();

        $this->assertFalse((bool) $guide->fresh()->to_be_most_popular);
    }

    public function test_a_second_run_would_re_promote_an_unpicked_post(): void
    {
        /*
         * An honest limit, recorded rather than claimed away.
         *
         * `where to_be_most_popular = false` stops the statement rewriting rows
         * it has already set, but it cannot tell "never picked" from "picked,
         * then deliberately unpicked". An editor who takes a post out of the
         * rail while leaving it on the home page strip WOULD have that undone
         * if this ran a second time.
         *
         * Safe only because it cannot run twice: Laravel records the migration,
         * so a repeat needs a rollback and re-migrate. If this ever becomes a
         * command an operator can re-run, it needs a real guard first.
         */
        [$site] = $this->siteWithKey();
        $post = $this->article($site->id, Article::TYPE_NEWS, 'Home strip only', true);

        $this->backfill();
        $post->forceFill(['to_be_most_popular' => false])->save();
        $this->backfill();

        $this->assertTrue(
            (bool) $post->fresh()->to_be_most_popular,
            'this documents the limit of the guard: the second run DOES re-promote it',
        );
    }

    public function test_the_flags_are_independent_afterwards(): void
    {
        // The point of the split: turning one off leaves the other alone.
        [$site] = $this->siteWithKey();
        $post = $this->article($site->id, Article::TYPE_NEWS, 'Both', true);

        $this->backfill();
        $post->forceFill(['featured' => false])->save();

        $this->assertFalse((bool) $post->fresh()->featured);
        $this->assertTrue(
            (bool) $post->fresh()->to_be_most_popular,
            'removing it from the home page strip must not remove it from the rail',
        );
    }
}
