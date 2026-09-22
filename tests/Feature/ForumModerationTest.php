<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumModerationLog;
use App\Models\ForumPost;
use App\Models\ForumSection;
use App\Models\ForumUser;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Moderation state transitions, and the one-level nesting rule.
 *
 * The queue is the feature's daily surface, so what it does to a row has to be
 * exact: an approve must publish AND restore, a spam mark must both record the
 * judgement and hide the post, and every action must be attributable.
 */
class ForumModerationTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private ForumArticle $article;

    private ForumUser $member;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site] = $this->siteWithKey(['forum_enabled' => true]);

        $section = ForumSection::create(['site_id' => $this->site->id, 'name' => 'Section']);
        $category = ForumCategory::create([
            'site_id' => $this->site->id, 'forum_section_id' => $section->id, 'name' => 'Board',
        ]);
        $this->article = ForumArticle::create([
            'site_id' => $this->site->id, 'forum_category_id' => $category->id,
            'title' => 'Topic', 'body' => 'x', 'status' => ForumArticle::STATUS_PUBLISHED,
        ]);
        $this->member = ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'M', 'email' => 'm@example.test', 'password' => 'x',
        ]);
    }

    private function makePost(string $status = ForumPost::STATUS_PENDING, ?int $parent = null): ForumPost
    {
        return ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $this->article->id,
            'parent_id' => $parent, 'forum_user_id' => $this->member->id,
            'body' => 'body', 'status' => $status,
        ]);
    }

    private function act(array $ids, string $action): \Illuminate\Testing\TestResponse
    {
        $this->actingAsAdmin();

        return $this->postJson('/api/v1/admin/forum-posts/act', ['ids' => $ids, 'action' => $action]);
    }

    public function test_approve_publishes_and_stamps_the_time(): void
    {
        $post = $this->makePost();

        $this->act([$post->id], 'approve')->assertOk()->assertJsonPath('data.affected', 1);

        $post->refresh();
        $this->assertSame(ForumPost::STATUS_APPROVED, $post->status);
        $this->assertNotNull($post->approved_at);
    }

    public function test_approving_a_deleted_post_also_restores_it(): void
    {
        $post = $this->makePost();
        $post->delete();

        $this->act([$post->id], 'approve')->assertOk();

        // Approving something invisible and leaving it invisible is the bug this
        // pins: the counters would say published, the page would show nothing.
        $this->assertFalse($post->fresh()->trashed());
        $this->assertSame(ForumPost::STATUS_APPROVED, $post->fresh()->status);
    }

    public function test_marking_spam_both_records_the_judgement_and_hides_the_post(): void
    {
        $post = $this->makePost(ForumPost::STATUS_APPROVED);

        $this->act([$post->id], 'spam')->assertOk();

        $fresh = ForumPost::withTrashed()->find($post->id);
        // Two different things: the status feeds the author's trust score, the
        // soft delete takes it off the page. Only "rejected" would leave it in
        // the moderator's default view forever.
        $this->assertSame(ForumPost::STATUS_SPAM, $fresh->status);
        $this->assertTrue($fresh->trashed());
    }

    public function test_every_action_is_logged_with_the_acting_admin(): void
    {
        $post = $this->makePost();
        $admin = $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/forum-posts/act', [
            'ids' => [$post->id], 'action' => 'reject', 'reason' => 'off topic',
        ])->assertOk();

        $log = ForumModerationLog::query()->where('subject_id', $post->id)->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('reject', $log->action);
        $this->assertSame(ForumPost::STATUS_PENDING, $log->from_status);
        $this->assertSame(ForumPost::STATUS_REJECTED, $log->to_status);
        $this->assertSame('off topic', $log->reason);
    }

    public function test_bulk_actions_apply_to_every_id(): void
    {
        $ids = collect(range(1, 5))->map(fn () => $this->makePost()->id)->all();

        $this->act($ids, 'approve')->assertOk()->assertJsonPath('data.affected', 5);

        $this->assertSame(5, ForumPost::query()->where('status', ForumPost::STATUS_APPROVED)->count());
    }

    public function test_the_queue_defaults_to_pending(): void
    {
        $this->makePost(ForumPost::STATUS_PENDING);
        $this->makePost(ForumPost::STATUS_APPROVED);
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/forum-posts')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_banning_a_member_revokes_their_sessions(): void
    {
        $this->member->createToken('phone');
        $this->assertSame(1, $this->member->tokens()->count());

        $this->actingAsAdmin();
        $this->patchJson("/api/v1/admin/forum-members/{$this->member->id}", [
            'status' => 'banned', 'reason' => 'spam',
        ])->assertOk();

        // A ban that leaves a live token is advice, not a ban.
        $this->assertSame(0, $this->member->fresh()->tokens()->count());
        $this->assertSame(ForumUser::STATUS_BANNED, $this->member->fresh()->status);
    }

    public function test_a_ban_never_carries_an_expiry(): void
    {
        $this->actingAsAdmin();
        $this->patchJson("/api/v1/admin/forum-members/{$this->member->id}", [
            'status' => 'banned', 'until' => now()->addDay()->toIso8601String(),
        ])->assertOk();

        // A ban with an expiry is a mute wearing the wrong label, so the expiry
        // is dropped rather than silently honoured.
        $this->assertNull($this->member->fresh()->banned_until);
    }

    public function test_the_database_refuses_a_second_level_of_nesting(): void
    {
        $post = $this->makePost(ForumPost::STATUS_APPROVED);
        $comment = $this->makePost(ForumPost::STATUS_APPROVED, $post->id);

        $this->assertSame(1, (int) $comment->depth);

        // The model derives depth from parent_id, so a reply-to-a-reply would
        // arrive as depth 1 with a depth-1 parent. The service refuses it; this
        // asserts the SCHEMA refuses the shape too, by writing the row the
        // service would have blocked.
        $this->expectException(\Illuminate\Database\QueryException::class);

        \Illuminate\Support\Facades\DB::table('forum_posts')->insert([
            'site_id' => $this->site->id,
            'forum_article_id' => $this->article->id,
            'parent_id' => $comment->id,
            'depth' => 2,
            'forum_user_id' => $this->member->id,
            'body' => 'three deep',
            'status' => ForumPost::STATUS_APPROVED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
