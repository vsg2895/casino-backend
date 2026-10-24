<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumModerationLog;
use App\Models\ForumUser;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The request contracts of the moderation writes: what each rejects, with
 * which message, and in which order relative to its 404s.
 *
 * ForumModerationTest owns the state transitions; this file owns the inputs.
 */
class ForumModerationValidationTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function member(Site $site): ForumUser
    {
        return ForumUser::create([
            'site_id' => $site->id, 'display_name' => 'M', 'email' => 'm@example.test', 'password' => 'x',
        ]);
    }

    public function test_act_returns_422_listing_every_missing_field(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/forum-posts/act', [])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The ids field is required. (and 1 more error)',
                'errors' => [
                    'ids' => ['The ids field is required.'],
                    'action' => ['The action field is required.'],
                ],
            ]);
    }

    public function test_act_returns_422_for_an_unknown_action(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/forum-posts/act', ['ids' => [1], 'action' => 'pin'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The selected action is invalid.',
                'errors' => ['action' => ['The selected action is invalid.']],
            ]);
    }

    public function test_act_returns_404_when_no_id_matches_a_post(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/forum-posts/act', ['ids' => [999], 'action' => 'approve'])
            ->assertNotFound();
    }

    public function test_member_returns_422_for_an_unknown_status_and_leaves_the_member_alone(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['forum_enabled' => true]);
        $member = $this->member($site);

        $this->patchJson("/api/v1/admin/forum-members/{$member->id}", ['status' => 'suspended'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The selected status is invalid.',
                'errors' => ['status' => ['The selected status is invalid.']],
            ]);

        $this->assertSame(ForumUser::STATUS_ACTIVE, $member->fresh()->status);
    }

    public function test_member_returns_422_for_a_mute_ending_in_the_past(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['forum_enabled' => true]);
        $member = $this->member($site);

        $this->patchJson("/api/v1/admin/forum-members/{$member->id}", ['status' => 'muted', 'until' => '2000-01-01'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The until field must be a date after now.',
                'errors' => ['until' => ['The until field must be a date after now.']],
            ]);
    }

    public function test_member_returns_404_for_an_unknown_member_before_validating(): void
    {
        $this->actingAsAdmin();

        $this->patchJson('/api/v1/admin/forum-members/999', [])->assertNotFound();
    }

    public function test_member_role_returns_422_for_an_unknown_role(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['forum_enabled' => true]);
        $member = $this->member($site);

        $this->patchJson("/api/v1/admin/forum-members/{$member->id}/role", ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The selected role is invalid.',
                'errors' => ['role' => ['The selected role is invalid.']],
            ]);

        $this->assertSame(ForumUser::ROLE_USER, $member->fresh()->role);
    }

    public function test_member_role_promotes_the_member_and_logs_the_change(): void
    {
        $admin = $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['forum_enabled' => true]);
        $member = $this->member($site);

        $this->patchJson("/api/v1/admin/forum-members/{$member->id}/role", ['role' => ForumUser::ROLE_MODERATOR])
            ->assertOk()
            ->assertExactJson(['data' => ['id' => $member->id, 'role' => 'moderator']]);

        $this->assertSame(ForumUser::ROLE_MODERATOR, $member->fresh()->role);
        $this->assertTrue(ForumModerationLog::query()
            ->where('user_id', $admin->id)
            ->where('subject_id', $member->id)
            ->exists());
    }
}
