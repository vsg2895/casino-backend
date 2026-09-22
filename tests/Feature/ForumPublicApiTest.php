<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumSection;
use App\Models\ForumUser;
use App\Models\Redirect;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The public forum API: keyset pagination at its boundaries, the feature gate,
 * anti-abuse, and the Forum → Reviews redirect.
 *
 * The pagination tests are the important ones. A keyset cursor that is off by
 * one repeats a post at every page boundary or drops one, and with 20 posts a
 * page nobody notices until a thread is long — at which point the data looks
 * corrupted rather than the pager looking wrong.
 */
class ForumPublicApiTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    private ForumCategory $category;

    private ForumArticle $article;

    private ForumUser $member;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site, $this->key] = $this->siteWithKey(['forum_enabled' => true]);

        $section = ForumSection::create(['site_id' => $this->site->id, 'name' => 'Gambling']);
        $this->category = ForumCategory::create([
            'site_id' => $this->site->id, 'forum_section_id' => $section->id, 'name' => 'Withdrawals',
        ]);
        $this->article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $this->category->id,
            'title' => 'Payout times', 'body' => '<p>x</p>', 'status' => ForumArticle::STATUS_PUBLISHED,
        ]);
        $this->member = ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'Member', 'email' => 'm@example.test',
            'password' => 'Correct-Horse-9!battery',
        ]);

        // NOT fillable — deliberately, so no mass-assignment can ever mark an
        // address confirmed. Set explicitly here, as the verify endpoint does.
        $this->member->forceFill(['email_verified_at' => now()])->save();
    }

    private function base(): string
    {
        return $this->publicBase($this->site);
    }

    private function seedPosts(int $n): array
    {
        return collect(range(1, $n))->map(fn (int $i) => ForumPost::create([
            'site_id' => $this->site->id,
            'forum_article_id' => $this->article->id,
            'forum_user_id' => $this->member->id,
            'body' => "Reply {$i}",
            'status' => ForumPost::STATUS_APPROVED,
        ])->id)->all();
    }

    // ── feature gate ─────────────────────────────────────────────────────────

    public function test_every_forum_route_404s_when_the_site_has_the_feature_off(): void
    {
        $this->site->forceFill(['forum_enabled' => false])->save();

        $this->getJson($this->base() . '/forum', $this->siteHeaders($this->key))->assertNotFound();
        $this->getJson($this->base() . '/forum/withdrawals', $this->siteHeaders($this->key))->assertNotFound();
        $this->getJson($this->base() . '/forum/withdrawals/payout-times', $this->siteHeaders($this->key))->assertNotFound();
    }

    // ── keyset pagination ────────────────────────────────────────────────────

    public function test_keyset_walks_every_post_exactly_once_with_no_overlap(): void
    {
        $ids = $this->seedPosts(47);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $url = $this->base() . '/forum/withdrawals/payout-times' . ($cursor ? "?after={$cursor}" : '');
            $res = $this->getJson($url, $this->siteHeaders($this->key))->assertOk();

            $page = collect($res->json('data.posts'))->pluck('id')->all();
            $seen = [...$seen, ...$page];
            $cursor = $res->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame($ids, $seen, 'every post, in order, exactly once');
        $this->assertSame(count($seen), count(array_unique($seen)), 'no post appears twice');
        $this->assertSame(3, $pages, '47 posts at 20 a page is three pages');
    }

    public function test_the_cursor_is_null_on_the_last_page(): void
    {
        // Exactly one page — the boundary where an off-by-one would offer a
        // "next" link to an empty page.
        $this->seedPosts(20);

        $res = $this->getJson($this->base() . '/forum/withdrawals/payout-times', $this->siteHeaders($this->key))->assertOk();

        $this->assertCount(20, $res->json('data.posts'));
        $this->assertNull($res->json('meta.next_cursor'));
    }

    public function test_one_post_past_a_full_page_yields_a_cursor(): void
    {
        $this->seedPosts(21);

        $res = $this->getJson($this->base() . '/forum/withdrawals/payout-times', $this->siteHeaders($this->key))->assertOk();

        $this->assertCount(20, $res->json('data.posts'));
        $this->assertNotNull($res->json('meta.next_cursor'));

        $next = $this->getJson(
            $this->base() . '/forum/withdrawals/payout-times?after=' . $res->json('meta.next_cursor'),
            $this->siteHeaders($this->key),
        )->assertOk();

        $this->assertCount(1, $next->json('data.posts'));
        $this->assertNull($next->json('meta.next_cursor'));
    }

    public function test_an_empty_thread_offers_no_cursor(): void
    {
        $res = $this->getJson($this->base() . '/forum/withdrawals/payout-times', $this->siteHeaders($this->key))->assertOk();

        $this->assertSame([], $res->json('data.posts'));
        $this->assertNull($res->json('meta.next_cursor'));
    }

    public function test_a_garbage_cursor_is_ignored_rather_than_erroring(): void
    {
        $this->seedPosts(5);

        // A cursor arrives from a URL, so it is user input. Treating an
        // unparseable one as "start from the beginning" beats a 500.
        $res = $this->getJson(
            $this->base() . '/forum/withdrawals/payout-times?after=not-a-number',
            $this->siteHeaders($this->key),
        )->assertOk();

        $this->assertCount(5, $res->json('data.posts'));
    }

    public function test_pending_and_deleted_posts_never_reach_the_public_thread(): void
    {
        $visible = $this->seedPosts(2);

        ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $this->article->id,
            'forum_user_id' => $this->member->id, 'body' => 'held', 'status' => ForumPost::STATUS_PENDING,
        ]);
        ForumPost::find($visible[1])->delete();

        $res = $this->getJson($this->base() . '/forum/withdrawals/payout-times', $this->siteHeaders($this->key))->assertOk();

        $this->assertSame([$visible[0]], collect($res->json('data.posts'))->pluck('id')->all());
    }

    public function test_comments_are_nested_one_level_and_no_deeper(): void
    {
        $post = ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $this->article->id,
            'forum_user_id' => $this->member->id, 'body' => 'parent', 'status' => ForumPost::STATUS_APPROVED,
        ]);
        ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $this->article->id, 'parent_id' => $post->id,
            'forum_user_id' => $this->member->id, 'body' => 'child', 'status' => ForumPost::STATUS_APPROVED,
        ]);

        $res = $this->getJson($this->base() . '/forum/withdrawals/payout-times', $this->siteHeaders($this->key))->assertOk();

        $posts = $res->json('data.posts');
        $this->assertCount(1, $posts, 'a comment is not a top-level post');
        $this->assertCount(1, $posts[0]['comments']);
        $this->assertSame([], $posts[0]['comments'][0]['comments'] ?? []);
    }

    // ── the index never reads forum_posts for its board list ─────────────────

    public function test_the_board_list_reports_totals_from_denormalised_columns(): void
    {
        $this->seedPosts(3);

        $res = $this->getJson($this->base() . '/forum', $this->siteHeaders($this->key))->assertOk();

        $board = $res->json('data.sections.0.categories.0');
        $this->assertSame(3, $board['posts_count']);
        $this->assertSame(1, $board['articles_count']);
        $this->assertNotNull($board['last_post']['article_title']);
    }

    // ── anti-abuse ───────────────────────────────────────────────────────────

    private function token(): string
    {
        return $this->member->createToken('test')->plainTextToken;
    }

    private function reply(array $payload, ?string $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders([
            ...$this->siteHeaders($this->key),
            ...($token ? ['Authorization' => 'Bearer ' . $token] : []),
        ])->postJson($this->base() . '/forum/articles/payout-times/posts', $payload);
    }

    public function test_a_guest_cannot_post(): void
    {
        $this->reply(['body' => 'hello there', 'website' => ''])->assertUnauthorized();
    }

    public function test_an_unverified_member_cannot_post(): void
    {
        $this->member->forceFill(['email_verified_at' => null])->save();

        $this->reply(['body' => 'hello there', 'website' => ''], $this->token())
            ->assertForbidden()
            ->assertJsonPath('message', 'Confirm your email address before posting.');
    }

    public function test_the_honeypot_rejects_a_filled_field(): void
    {
        $this->reply(['body' => 'hello there', 'website' => 'http://spam.test'], $this->token())
            ->assertStatus(422);
    }

    public function test_a_missing_honeypot_field_is_also_rejected(): void
    {
        // `present` as well as `max:0`: a missing field means the submission did
        // not come from the form we served.
        $this->reply(['body' => 'hello there'], $this->token())->assertStatus(422);
    }

    public function test_a_new_members_first_posts_are_held_for_review(): void
    {
        $res = $this->reply(['body' => 'my first post here', 'website' => ''], $this->token())
            ->assertCreated();

        $this->assertTrue($res->json('data.pending'));
        // The member is told WHY, not just that it is pending.
        $this->assertStringContainsString('moderator', (string) $res->json('data.message'));
    }

    public function test_a_trusted_member_publishes_immediately(): void
    {
        $this->member->forceFill(['approved_posts_count' => 10])->save();

        $res = $this->reply(['body' => 'a trusted reply', 'website' => ''], $this->token())->assertCreated();

        $this->assertFalse($res->json('data.pending'));
        $this->assertNotNull($res->json('data.post.id'));
    }

    public function test_a_link_from_an_untrusted_member_is_held_even_past_pre_moderation(): void
    {
        // Past the pre-moderation threshold (3) but below the link one (5).
        $this->member->forceFill(['approved_posts_count' => 4])->save();

        $res = $this->reply(['body' => 'check https://spam.test out', 'website' => ''], $this->token())->assertCreated();

        $this->assertTrue($res->json('data.pending'));
        $this->assertStringContainsString('links', (string) $res->json('data.message'));
    }

    public function test_a_post_is_stored_as_plain_text_with_every_tag_stripped(): void
    {
        $this->member->forceFill(['approved_posts_count' => 10])->save();

        $this->reply([
            'body' => '<script>alert(1)</script>Hello <b>there</b>',
            'website' => '',
        ], $this->token())->assertCreated();

        $stored = ForumPost::query()->latest('id')->first();
        $this->assertSame('alert(1)Hello there', $stored->body);
        $this->assertStringNotContainsString('<', $stored->body);
    }

    public function test_a_banned_member_cannot_post(): void
    {
        $this->member->forceFill(['status' => ForumUser::STATUS_BANNED])->save();

        $this->reply(['body' => 'let me in', 'website' => ''], $this->token())->assertForbidden();
    }

    public function test_a_locked_discussion_refuses_new_replies(): void
    {
        $this->article->forceFill(['locked' => true])->save();

        $this->reply(['body' => 'too late', 'website' => ''], $this->token())
            ->assertStatus(422)
            ->assertJsonPath('message', 'This discussion is locked — no new replies.');
    }

    public function test_posting_is_rate_limited_per_member(): void
    {
        $this->member->forceFill(['approved_posts_count' => 10])->save();
        $token = $this->token();

        // 3 a minute per account. The fourth must be refused.
        for ($i = 0; $i < 3; $i++) {
            $this->reply(['body' => "burst message {$i}", 'website' => ''], $token)->assertCreated();
        }

        $this->reply(['body' => 'one too many', 'website' => ''], $token)->assertStatus(429);
    }

    public function test_a_guest_may_report_a_post(): void
    {
        $post = ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $this->article->id,
            'forum_user_id' => $this->member->id, 'body' => 'spammy', 'status' => ForumPost::STATUS_APPROVED,
        ]);

        // Reporting is the one write that must NOT require an account: the
        // person best placed to spot spam is a reader with no intention of
        // registering.
        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . "/forum/posts/{$post->id}/report", ['reason' => 'spam', 'website' => ''])
            ->assertCreated();

        $this->assertDatabaseHas('forum_reports', ['forum_post_id' => $post->id, 'status' => 'open']);
    }

    // ── roles ────────────────────────────────────────────────────────────────

    public function test_registration_always_produces_a_plain_user(): void
    {
        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/register', [
                'display_name' => 'New Person',
                'email' => 'new.person@example.test',
                'password' => 'Correct-Horse-9!battery',
                'password_confirmation' => 'Correct-Horse-9!battery',
                'website' => '',
            ])->assertCreated();

        $this->assertDatabaseHas('forum_users', [
            'email'  => 'new.person@example.test',
            'role'   => ForumUser::ROLE_USER,
            'status' => ForumUser::STATUS_ACTIVE,
        ]);
    }

    public function test_registration_cannot_grant_itself_a_role(): void
    {
        // `role` is absent from $fillable precisely so this cannot work. Without
        // that, anyone could POST themselves a moderator account.
        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/register', [
                'display_name' => 'Sneaky',
                'email' => 'sneaky@example.test',
                'password' => 'Correct-Horse-9!battery',
                'password_confirmation' => 'Correct-Horse-9!battery',
                'website' => '',
                'role'   => ForumUser::ROLE_MODERATOR,
                'status' => ForumUser::STATUS_ACTIVE,
            ])->assertCreated();

        $this->assertSame(
            ForumUser::ROLE_USER,
            ForumUser::query()->where('email', 'sneaky@example.test')->value('role'),
        );
    }

    public function test_the_signed_in_profile_reports_the_role(): void
    {
        $token = $this->member->createToken('t')->plainTextToken;

        $this->withHeaders([...$this->siteHeaders($this->key), 'Authorization' => 'Bearer ' . $token])
            ->getJson($this->base() . '/forum/members/me')
            ->assertOk()
            ->assertJsonPath('data.role', ForumUser::ROLE_USER);
    }

    public function test_an_admin_can_promote_a_member_without_touching_their_status(): void
    {
        $this->member->forceFill(['status' => ForumUser::STATUS_MUTED])->save();
        $this->actingAsAdmin();

        $this->patchJson("/api/v1/admin/forum-members/{$this->member->id}/role", [
            'role' => ForumUser::ROLE_MODERATOR,
        ])->assertOk()->assertJsonPath('data.role', ForumUser::ROLE_MODERATOR);

        $fresh = $this->member->fresh();
        $this->assertSame(ForumUser::ROLE_MODERATOR, $fresh->role);
        // Role and status are separate axes — promoting must not un-mute.
        $this->assertSame(ForumUser::STATUS_MUTED, $fresh->status);
    }

    public function test_the_admin_member_list_is_filterable(): void
    {
        $this->member->forceFill(['role' => ForumUser::ROLE_MODERATOR])->save();
        ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'Plain', 'email' => 'plain@example.test', 'password' => 'x',
        ]);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/forum-members?role=moderator')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.display_name', 'Member');

        $this->getJson('/api/v1/admin/forum-members?search=plain')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // ── Part 1: the Forum → Reviews redirect ─────────────────────────────────

    public function test_the_forum_to_reviews_redirect_is_seeded_as_a_301(): void
    {
        // Seeded by migration for every site; `proxy.ts` on the front end reads
        // it from /redirects and issues the response.
        $redirect = Redirect::query()
            ->where('site_id', $this->site->id)
            ->where('source_path', '/forum')
            ->first();

        $this->assertNotNull($redirect, 'the rename must leave a redirect behind');
        $this->assertSame('/reviews', $redirect->destination_path);
        $this->assertSame(301, (int) $redirect->status_code);
    }

    public function test_enabling_the_forum_deactivates_the_redirect_that_would_shadow_it(): void
    {
        $this->site->forceFill(['forum_enabled' => false])->save();
        $this->assertTrue((bool) Redirect::query()
            ->where('site_id', $this->site->id)->where('source_path', '/forum')->value('active'));

        $this->site->forceFill(['forum_enabled' => true])->save();

        // A redirect wins before routing does, so with both live the new forum
        // index would be unreachable and nothing would explain why.
        $this->assertFalse((bool) Redirect::query()
            ->where('site_id', $this->site->id)->where('source_path', '/forum')->value('active'));
    }

    public function test_the_public_redirects_endpoint_stops_serving_it_once_the_forum_is_live(): void
    {
        $this->site->forceFill(['forum_enabled' => true])->save();

        $res = $this->getJson($this->base() . '/redirects', $this->siteHeaders($this->key))->assertOk();

        $sources = collect($res->json('data') ?? $res->json())->pluck('source_path')->all();
        $this->assertNotContains('/forum', $sources);
    }

    /**
     * A visitor's password is deliberately simple: six characters, no
     * confirmation field. The admin policy (12 characters, symbols) is for
     * accounts that can edit every site, not for someone who wants to reply.
     */
    public function test_a_visitor_can_register_with_a_simple_password_and_no_confirmation(): void
    {
        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/register', [
                'display_name' => 'Casual Visitor',
                'email'        => 'casual@example.test',
                'password'     => 'simple',
                'website'      => '',
            ])->assertCreated();

        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/register', [
                'display_name' => 'Too Short',
                'email'        => 'short@example.test',
                'password'     => 'abc',
                'website'      => '',
            ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
