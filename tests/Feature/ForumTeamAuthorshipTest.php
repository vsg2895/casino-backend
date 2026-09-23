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
use Database\Seeders\ForumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Who the forum says wrote what, and the editorial seeder that relies on it.
 *
 * The two halves of the authorship rule are structural rather than conditional:
 * `forum_articles.user_id` is a foreign key to the ADMIN `users` table and
 * `forum_posts.forum_user_id` to `forum_users`, so a discussion is always staff
 * and a reply is always a member. These tests pin the PUBLIC consequence of
 * that — a staff name must never be published, a member's must never be
 * replaced — because both are quiet failures otherwise: nothing errors, the
 * wrong name simply appears on a live page.
 */
class ForumTeamAuthorshipTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site, $this->key] = $this->siteWithKey([
            'slug'         => 'winpalack',
            'name'         => 'Winpalack',
            'forum_enabled' => true,
        ]);
    }

    private function admin(): User
    {
        $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);

        $user = User::create([
            'name'     => 'Jane Administrator',
            'email'    => 'jane@example.test',
            'password' => 'Correct-Horse-9!battery',
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: ForumCategory, 1: ForumSection} */
    private function board(): array
    {
        $section = ForumSection::create(['site_id' => $this->site->id, 'name' => 'Gambling Section']);
        $category = ForumCategory::create([
            'site_id' => $this->site->id, 'forum_section_id' => $section->id, 'name' => 'Trust & Licensing',
        ]);

        return [$category, $section];
    }

    // ── Test 1 — admin-authored content is published as the team ─────────────

    public function test_an_admin_authored_discussion_is_published_as_the_team(): void
    {
        $admin = $this->admin();
        [$category] = $this->board();

        $article = ForumArticle::create([
            'site_id' => $this->site->id,
            'forum_category_id' => $category->id,
            'user_id' => $admin->id,
            'title'   => 'How do you check a licence?',
            'body'    => '<p>Editorial opener.</p>',
            'status'  => ForumArticle::STATUS_PUBLISHED,
        ]);

        $response = $this->withHeaders(['X-Site-Key' => $this->key])
            ->getJson($this->publicBase($this->site) . '/forum/' . $category->slug . '/' . $article->slug)
            ->assertOk();

        // Derived from the site's own name, not hardcoded: the same admin
        // writes for six domains.
        $response->assertJsonPath('data.article.author.name', 'Winpalack Team');

        // The personal name must not appear ANYWHERE in the public payload —
        // not in the author field, not in a byline, not in structured data.
        $this->assertStringNotContainsString('Jane Administrator', $response->getContent());
    }

    public function test_the_admin_panel_still_names_the_real_author(): void
    {
        $admin = $this->admin();
        [$category] = $this->board();

        $article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $category->id,
            'user_id' => $admin->id, 'title' => 'Internal view', 'body' => '<p>x</p>',
            'status' => ForumArticle::STATUS_PUBLISHED,
        ])->load('author:id,name');

        // An editor triaging the list needs to know who wrote which thread —
        // the team label is a PUBLIC presentation, not a record change.
        $this->assertSame(
            'Jane Administrator',
            (new \App\Http\Resources\Forum\ForumArticleResource($article))->withRealAuthor()->resolve()['author']['name'],
        );

        // The row itself still points at the real admin.
        $this->assertSame($admin->id, $article->fresh()->user_id);
    }

    // ── Test 2 — a member keeps their own name ───────────────────────────────

    public function test_a_members_reply_is_published_under_their_own_name(): void
    {
        $admin = $this->admin();
        [$category] = $this->board();

        $article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $category->id,
            'user_id' => $admin->id, 'title' => 'Payout times', 'body' => '<p>x</p>',
            'status' => ForumArticle::STATUS_PUBLISHED,
        ]);

        $member = ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'Dana Player',
            'email' => 'dana@example.test', 'password' => 'Correct-Horse-9!battery',
        ]);
        $member->forceFill(['email_verified_at' => now()])->save();

        ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $article->id,
            'forum_user_id' => $member->id, 'body' => 'A genuine reply.',
            'status' => ForumPost::STATUS_APPROVED,
        ]);

        $response = $this->withHeaders(['X-Site-Key' => $this->key])
            ->getJson($this->publicBase($this->site) . '/forum/' . $category->slug . '/' . $article->slug)
            ->assertOk();

        $response->assertJsonPath('data.posts.0.author.display_name', 'Dana Player');

        // The team label must never reach a member's post. The two author
        // fields come from different tables and different resources, and this
        // is the assertion that keeps them apart.
        $this->assertNotSame('Winpalack Team', $response->json('data.posts.0.author.display_name'));
    }

    // ── staff replies ────────────────────────────────────────────────────────

    public function test_a_team_reply_is_published_under_the_team_name(): void
    {
        $admin = $this->admin();
        [$category] = $this->board();

        $article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $category->id,
            'user_id' => $admin->id, 'title' => 'Payout times', 'body' => '<p>x</p>',
            'status' => ForumArticle::STATUS_PUBLISHED,
        ]);

        $post = app(\App\Services\Forum\ForumPostService::class)
            ->createAsTeam($article, $admin, ['body' => 'An editorial reply.']);

        // Stored against the real admin, with no member row invented for it.
        $this->assertSame($admin->id, $post->user_id);
        $this->assertNull($post->forum_user_id);
        $this->assertSame(ForumPost::STATUS_APPROVED, $post->status);
        $this->assertSame(0, ForumUser::where('site_id', $this->site->id)->count());

        $response = $this->withHeaders(['X-Site-Key' => $this->key])
            ->getJson($this->publicBase($this->site) . '/forum/' . $category->slug . '/' . $article->slug)
            ->assertOk();

        $response->assertJsonPath('data.posts.0.author.display_name', 'Winpalack Team');
        $response->assertJsonPath('data.posts.0.author.is_team', true);
        // No member profile to link to, and no tally that would mean anything.
        $response->assertJsonPath('data.posts.0.author.slug', null);
        $this->assertStringNotContainsString('Jane Administrator', $response->getContent());
    }

    public function test_latest_posts_names_the_team_rather_than_nobody(): void
    {
        $admin = $this->admin();
        [$category] = $this->board();

        $article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $category->id,
            'user_id' => $admin->id, 'title' => 'Payout times', 'body' => '<p>x</p>',
            'status' => ForumArticle::STATUS_PUBLISHED,
        ]);

        app(\App\Services\Forum\ForumPostService::class)
            ->createAsTeam($article, $admin, ['body' => 'An editorial reply.']);

        // The Latest Posts tab reads forum_posts directly rather than through
        // the resource, so it needs its own assertion — a staff reply there
        // would otherwise render with a blank author.
        $this->withHeaders(['X-Site-Key' => $this->key])
            ->getJson($this->publicBase($this->site) . '/forum')
            ->assertOk()
            ->assertJsonPath('data.latest.0.author', 'Winpalack Team');
    }

    /**
     * The invariant that keeps the two author columns from contradicting each
     * other. Without it a post could belong to nobody and render as a blank
     * byline, or to both and be a contradiction nothing resolves.
     */
    public function test_a_post_must_have_exactly_one_author(): void
    {
        $admin = $this->admin();
        [$category] = $this->board();

        $article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $category->id,
            'user_id' => $admin->id, 'title' => 'x', 'body' => '<p>x</p>',
            'status' => ForumArticle::STATUS_PUBLISHED,
        ]);

        $member = ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'Dana',
            'email' => 'dana@example.test', 'password' => 'Correct-Horse-9!battery',
        ]);

        // Neither.
        $this->expectException(\LogicException::class);
        ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $article->id,
            'body' => 'orphan', 'status' => ForumPost::STATUS_APPROVED,
        ]);
    }

    public function test_a_post_may_not_have_both_authors(): void
    {
        $admin = $this->admin();
        [$category] = $this->board();

        $article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $category->id,
            'user_id' => $admin->id, 'title' => 'x', 'body' => '<p>x</p>',
            'status' => ForumArticle::STATUS_PUBLISHED,
        ]);

        $member = ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'Dana',
            'email' => 'dana2@example.test', 'password' => 'Correct-Horse-9!battery',
        ]);

        $this->expectException(\LogicException::class);
        ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $article->id,
            'forum_user_id' => $member->id, 'user_id' => $admin->id,
            'body' => 'both', 'status' => ForumPost::STATUS_APPROVED,
        ]);
    }

    /** A staff reply must not move any member's trust counters. */
    public function test_a_team_reply_does_not_touch_member_counters(): void
    {
        $admin = $this->admin();
        [$category] = $this->board();

        $article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $category->id,
            'user_id' => $admin->id, 'title' => 'x', 'body' => '<p>x</p>',
            'status' => ForumArticle::STATUS_PUBLISHED,
        ]);

        $member = ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'Dana',
            'email' => 'dana3@example.test', 'password' => 'Correct-Horse-9!battery',
        ]);

        app(\App\Services\Forum\ForumPostService::class)
            ->createAsTeam($article, $admin, ['body' => 'An editorial reply.']);

        $member->refresh();
        $this->assertSame(0, (int) $member->posts_count);
        $this->assertSame(0, (int) $member->approved_posts_count);

        // The article's own count IS derived by the observer, because a reply
        // genuinely exists on it.
        $this->assertSame(1, (int) $article->fresh()->posts_count);
    }

    // ── Test 3 — the seeder is safe to run twice ─────────────────────────────

    public function test_the_seeder_is_idempotent(): void
    {
        $this->admin();

        $this->seed(ForumSeeder::class);

        $counts = fn (): array => [
            'sections'    => ForumSection::where('site_id', $this->site->id)->count(),
            'boards'      => ForumCategory::where('site_id', $this->site->id)->count(),
            'discussions' => ForumArticle::where('site_id', $this->site->id)->count(),
            // forum_posts has no unique column, so idempotency there is
            // "has the team already replied on this discussion".
            'team_posts'  => ForumPost::where('site_id', $this->site->id)->whereNotNull('user_id')->count(),
        ];

        $first = $counts();

        $this->seed(ForumSeeder::class);

        $this->assertSame($first, $counts());
        $this->assertSame(4, $first['sections']);
        $this->assertSame(20, $first['boards']);
        $this->assertSame(10, $first['discussions'], 'exactly ten editorial openers');
        $this->assertSame(10, ForumPost::where('site_id', $this->site->id)->count(), 'one team reply each');
    }

    // ── Test 4 — no invented people, no invented activity ────────────────────

    public function test_the_seeder_creates_no_users_and_no_activity(): void
    {
        $admin = $this->admin();

        $usersBefore = User::count();

        $this->seed(ForumSeeder::class);

        // No admin account invented, and no "Winpalack Team" login.
        $this->assertSame($usersBefore, User::count());
        $this->assertSame(0, User::where('name', 'like', '%Winpalack Team%')->count());

        // No members invented, and no reply attributed to one. The ten posts
        // that DO exist are the editorial team's own, openly labelled as such.
        $this->assertSame(0, ForumUser::where('site_id', $this->site->id)->count());
        $this->assertSame(0, ForumPost::where('site_id', $this->site->id)->whereNotNull('forum_user_id')->count());
        $this->assertSame(10, ForumPost::where('site_id', $this->site->id)->whereNotNull('user_id')->count());

        foreach (ForumPost::where('site_id', $this->site->id)->get() as $post) {
            $this->assertSame($admin->id, $post->user_id);
        }

        $articles = ForumArticle::where('site_id', $this->site->id)->get();

        foreach ($articles as $article) {
            // Authored by the REAL existing admin.
            $this->assertSame($admin->id, $article->user_id);
            // Every counter left for the application to derive from real
            // activity. A seeded "1.2k views" is the lie that makes every
            // other number on the page worthless.
            // One reply each, and the observer derived it — not the seeder.
            $this->assertSame(1, (int) $article->posts_count);
            $this->assertSame(0, (int) $article->views_count);
            $this->assertSame(0, (int) $article->hot_score);
            // The application's own published state, and a real date.
            $this->assertSame(ForumArticle::STATUS_PUBLISHED, $article->status);
            $this->assertNotNull($article->published_at);
        }
    }

    public function test_the_seeder_refuses_to_run_without_an_existing_admin(): void
    {
        // No admin exists: the seeder must stop rather than author the threads
        // as a member or invent an account.
        $this->seed(ForumSeeder::class);

        $this->assertSame(0, ForumArticle::where('site_id', $this->site->id)->count());
        $this->assertSame(0, User::count());
    }
}
