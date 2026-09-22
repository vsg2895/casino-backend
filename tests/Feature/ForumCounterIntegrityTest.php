<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumSection;
use App\Models\ForumUser;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The denormalised counters must equal what a COUNT() would return, through
 * every transition — because nothing ever runs that COUNT() in production.
 *
 * This is the highest-value test file in the feature. The counters are the whole
 * reason the forum index can render without touching a 50,000-row table, and a
 * drift in them is invisible: the page still loads, the numbers are just wrong,
 * and nobody notices for months.
 *
 * Two bugs were found by exercising these paths rather than by reading the
 * observer, and both are pinned below:
 *
 *   - `restore()` fires BOTH `updated` and `restored`, so an increment in each
 *     double-counted every restore;
 *   - the member's `posts_count` was never maintained at all.
 */
class ForumCounterIntegrityTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private ForumCategory $category;

    private ForumArticle $article;

    private ForumUser $member;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site] = $this->siteWithKey(['forum_enabled' => true]);

        $section = ForumSection::create([
            'site_id' => $this->site->id,
            'name'    => 'Gambling Section',
        ]);

        $this->category = ForumCategory::create([
            'site_id'          => $this->site->id,
            'forum_section_id' => $section->id,
            'name'             => 'Withdrawals',
        ]);

        $this->article = ForumArticle::create([
            'site_id'           => $this->site->id,
            'forum_category_id' => $this->category->id,
            'user_id'           => User::factory()->create()->id,
            'title'             => 'How long did your withdrawal take?',
            'body'              => '<p>Tell us.</p>',
            'status'            => ForumArticle::STATUS_PUBLISHED,
        ]);

        $this->member = ForumUser::create([
            'site_id'      => $this->site->id,
            'display_name' => 'Test Member',
            'email'        => 'member@example.test',
            'password'     => 'irrelevant-for-these-tests',
        ]);
    }

    /** Named `makePost` because `post()` is the framework's HTTP helper. */
    private function makePost(string $status = ForumPost::STATUS_APPROVED, ?int $parentId = null): ForumPost
    {
        return ForumPost::create([
            'site_id'          => $this->site->id,
            'forum_article_id' => $this->article->id,
            'parent_id'        => $parentId,
            'forum_user_id'    => $this->member->id,
            'body'             => 'A reply.',
            'status'           => $status,
        ]);
    }

    /** @param array{article:int, category:int, approved:int, authored:int} $expected */
    private function assertCounters(array $expected, string $context): void
    {
        $this->assertSame($expected['article'], (int) $this->article->fresh()->posts_count, "article.posts_count after {$context}");
        $this->assertSame($expected['category'], (int) $this->category->fresh()->posts_count, "category.posts_count after {$context}");
        $this->assertSame($expected['approved'], (int) $this->member->fresh()->approved_posts_count, "member.approved_posts_count after {$context}");
        $this->assertSame($expected['authored'], (int) $this->member->fresh()->posts_count, "member.posts_count after {$context}");
    }

    public function test_a_pending_post_counts_toward_nothing_but_authorship(): void
    {
        $this->makePost(ForumPost::STATUS_PENDING);

        // Written, so it is theirs — but not accepted, so it earns no trust and
        // does not appear in any total a reader sees.
        $this->assertCounters(['article' => 0, 'category' => 0, 'approved' => 0, 'authored' => 1], 'pending create');
    }

    public function test_approving_a_pending_post_increments_every_total(): void
    {
        $post = $this->makePost(ForumPost::STATUS_PENDING);

        $post->update(['status' => ForumPost::STATUS_APPROVED]);

        $this->assertCounters(['article' => 1, 'category' => 1, 'approved' => 1, 'authored' => 1], 'approve');
    }

    public function test_rejecting_an_approved_post_decrements_every_total(): void
    {
        $post = $this->makePost();
        $this->assertCounters(['article' => 1, 'category' => 1, 'approved' => 1, 'authored' => 1], 'create approved');

        $post->update(['status' => ForumPost::STATUS_REJECTED]);

        $this->assertCounters(['article' => 0, 'category' => 0, 'approved' => 0, 'authored' => 1], 'reject');
    }

    public function test_soft_delete_and_restore_are_symmetric(): void
    {
        $post = $this->makePost();

        $post->delete();
        $this->assertCounters(['article' => 0, 'category' => 0, 'approved' => 0, 'authored' => 0], 'soft delete');

        $post->restore();
        // REGRESSION: restore() fires both `updated` and `restored`. An
        // increment in each made this 2/2/2 — the article claimed two posts
        // where one existed.
        $this->assertCounters(['article' => 1, 'category' => 1, 'approved' => 1, 'authored' => 1], 'restore');
    }

    public function test_restoring_a_rejected_post_restores_no_totals(): void
    {
        $post = $this->makePost(ForumPost::STATUS_REJECTED);
        $post->delete();
        $post->restore();

        // It never counted, so coming back cannot make it count. Only
        // authorship returns.
        $this->assertCounters(['article' => 0, 'category' => 0, 'approved' => 0, 'authored' => 1], 'restore rejected');
    }

    public function test_force_deleting_a_live_post_decrements_once(): void
    {
        $post = $this->makePost();

        $post->forceDelete();

        $this->assertCounters(['article' => 0, 'category' => 0, 'approved' => 0, 'authored' => 0], 'force delete');
    }

    public function test_force_deleting_an_already_soft_deleted_post_does_not_decrement_twice(): void
    {
        $post = $this->makePost();
        $post->delete();
        $this->assertCounters(['article' => 0, 'category' => 0, 'approved' => 0, 'authored' => 0], 'soft delete');

        $post->forceDelete();

        // Still zero, not minus one. GREATEST() clamps an unsigned underflow,
        // so a double decrement would be invisible in the column and only show
        // up as drift against a recount — which is why this is asserted here.
        $this->assertCounters(['article' => 0, 'category' => 0, 'approved' => 0, 'authored' => 0], 'force delete after soft delete');
    }

    public function test_comments_count_exactly_like_posts(): void
    {
        $parent = $this->makePost();
        $this->makePost(ForumPost::STATUS_APPROVED, $parent->id);

        $this->assertCounters(['article' => 2, 'category' => 2, 'approved' => 2, 'authored' => 2], 'comment');
    }

    public function test_the_last_post_pointer_falls_back_when_the_newest_is_removed(): void
    {
        $first = $this->makePost();
        $second = $this->makePost();

        $this->assertSame($second->id, (int) $this->article->fresh()->last_post_id);

        $second->delete();

        // Recomputed, not blanked: the pointer is "the most recent APPROVED
        // post", which after a deletion is a query rather than the row we held.
        $this->assertSame($first->id, (int) $this->article->fresh()->last_post_id);
    }

    public function test_deleting_an_article_removes_its_contribution_from_the_category(): void
    {
        $this->makePost();
        $this->makePost();

        $other = ForumArticle::create([
            'site_id'           => $this->site->id,
            'forum_category_id' => $this->category->id,
            'title'             => 'Another topic',
            'body'              => 'x',
            'status'            => ForumArticle::STATUS_PUBLISHED,
        ]);

        ForumPost::create([
            'site_id'          => $this->site->id,
            'forum_article_id' => $other->id,
            'forum_user_id'    => $this->member->id,
            'body'             => 'elsewhere',
            'status'           => ForumPost::STATUS_APPROVED,
        ]);

        $this->assertSame(3, (int) $this->category->fresh()->posts_count);
        $this->assertSame(2, (int) $this->category->fresh()->articles_count);

        $this->article->delete();

        // Loses the article AND exactly the posts it contributed — never the
        // other article's.
        $this->assertSame(1, (int) $this->category->fresh()->posts_count);
        $this->assertSame(1, (int) $this->category->fresh()->articles_count);
    }

    public function test_recount_command_agrees_with_the_observers(): void
    {
        $a = $this->makePost();
        $this->makePost(ForumPost::STATUS_PENDING);
        $b = $this->makePost();
        $b->delete();
        $a->update(['status' => ForumPost::STATUS_SPAM]);
        $a->update(['status' => ForumPost::STATUS_APPROVED]);

        // An INDEPENDENT recalculation from the source rows. If it finds drift
        // the observers are wrong, whatever the assertions above said.
        $this->artisan('forum:recount', ['--site' => $this->site->slug, '--dry-run' => true])
            ->expectsOutputToContain('All counters already correct.')
            ->assertSuccessful();
    }
}
