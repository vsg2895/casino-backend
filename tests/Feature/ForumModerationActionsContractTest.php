<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RevalidateNextJsSites;
use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumModerationLog;
use App\Models\ForumPost;
use App\Models\ForumReport;
use App\Models\ForumSection;
use App\Models\ForumUser;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Side effects and exact bodies of the moderation writes that
 * {@see ForumModerationTest} only asserts by status: report resolution, the
 * per-site revalidation, delete/restore, and the member status/role answers.
 */
class ForumModerationActionsContractTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private ForumArticle $article;

    private ForumUser $member;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-27 12:00:00');

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makePost(string $status = ForumPost::STATUS_PENDING): ForumPost
    {
        return ForumPost::create([
            'site_id' => $this->site->id, 'forum_article_id' => $this->article->id,
            'forum_user_id' => $this->member->id, 'body' => 'body', 'status' => $status,
        ]);
    }

    private function report(ForumPost $post): ForumReport
    {
        return ForumReport::create([
            'site_id' => $this->site->id, 'forum_post_id' => $post->id,
            'forum_user_id' => $this->member->id, 'reason' => 'spam',
        ])->refresh();
    }

    public function test_approve_dismisses_open_reports_and_revalidates_the_site(): void
    {
        Bus::fake([RevalidateNextJsSites::class]);
        $admin = $this->actingAsAdmin();
        $post = $this->makePost();
        $report = $this->report($post);

        $this->postJson('/api/v1/admin/forum-posts/act', ['ids' => [$post->id], 'action' => 'approve'])
            ->assertOk()
            ->assertExactJson(['data' => ['affected' => 1, 'action' => 'approve']]);

        $report->refresh();
        $this->assertSame(ForumReport::STATUS_DISMISSED, $report->status);
        $this->assertSame($admin->id, (int) $report->handled_by);
        $this->assertTrue($report->handled_at->equalTo(Carbon::now()));
        Bus::assertDispatched(
            RevalidateNextJsSites::class,
            fn (RevalidateNextJsSites $job): bool => $job->tags === ['forum'] && $job->siteIds === [$this->site->id],
        );
    }

    public function test_reject_actions_open_reports_and_logs_the_reason(): void
    {
        Bus::fake([RevalidateNextJsSites::class]);
        $admin = $this->actingAsAdmin();
        $post = $this->makePost();
        $report = $this->report($post);

        $this->postJson('/api/v1/admin/forum-posts/act', ['ids' => [$post->id], 'action' => 'reject', 'reason' => 'off-topic'])
            ->assertOk();

        $this->assertSame(ForumReport::STATUS_ACTIONED, $report->refresh()->status);
        $log = ForumModerationLog::query()->sole();
        $this->assertSame(
            [$this->site->id, $admin->id, 'post', $post->id, 'reject', 'pending', 'rejected', 'off-topic'],
            [(int) $log->site_id, (int) $log->user_id, $log->subject_type, (int) $log->subject_id,
                $log->action, $log->from_status, $log->to_status, $log->reason],
        );
    }

    public function test_delete_then_restore(): void
    {
        Bus::fake([RevalidateNextJsSites::class]);
        $this->actingAsAdmin();
        $post = $this->makePost(ForumPost::STATUS_APPROVED);

        $this->postJson('/api/v1/admin/forum-posts/act', ['ids' => [$post->id], 'action' => 'delete'])->assertOk();
        $this->assertTrue(ForumPost::withTrashed()->find($post->id)->trashed());

        $this->postJson('/api/v1/admin/forum-posts/act', ['ids' => [$post->id], 'action' => 'restore'])
            ->assertOk()
            ->assertExactJson(['data' => ['affected' => 1, 'action' => 'restore']]);
        $this->assertFalse(ForumPost::withTrashed()->find($post->id)->trashed());
        $this->assertSame(['delete', 'restore'], ForumModerationLog::query()->orderBy('id')->pluck('action')->all());
    }

    public function test_mute_answers_with_its_expiry_and_lifting_it_clears_the_reason(): void
    {
        $admin = $this->actingAsAdmin();
        $url = "/api/v1/admin/forum-members/{$this->member->id}";
        $until = Carbon::now()->addDays(2);

        $this->patchJson($url, ['status' => 'muted', 'until' => $until->toIso8601String(), 'reason' => 'heated'])
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $this->member->id,
                'status' => 'muted',
                'banned_until' => $until->toISOString(),
            ]]);
        $this->assertSame('heated', $this->member->refresh()->ban_reason);

        $this->patchJson($url, ['status' => 'active'])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $this->member->id, 'status' => 'active', 'banned_until' => null]]);
        $this->assertNull($this->member->refresh()->ban_reason);

        $this->assertSame(
            [['status:muted', 'active', 'muted', 'heated'], ['status:active', 'muted', 'active', null]],
            ForumModerationLog::query()->orderBy('id')->get()
                ->map(fn (ForumModerationLog $l): array => [$l->action, $l->from_status, $l->to_status, $l->reason])
                ->all(),
        );
        $this->assertSame([$admin->id], ForumModerationLog::query()->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all());
    }
}
