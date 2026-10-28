<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumUser;
use App\Models\Site;
use App\Models\UniOne\UniOneReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * A count above a filtered table has to describe THAT table.
 *
 * The bug both of these guard is the same one, and it is worse than showing no
 * number at all: the header reported the whole list while the rows underneath
 * it were a filtered slice, so the number looked like it described what you
 * were looking at and did not.
 *
 * The one thing that must NOT be filtered is tested here too: the list of
 * `last_statuses` behind the "Last sent" dropdown. Narrowing those by the
 * current filter would remove the options an operator is trying to switch
 * between — pick one and the rest vanish, with no way back.
 */
class FilteredCountsTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function receiver(string $email, string $status, ?string $lastStatus = null): UniOneReceiver
    {
        $receiver = UniOneReceiver::create(['email' => $email]);

        // Written past the model so the row is exactly what is being asserted,
        // whatever defaults the status transitions would otherwise apply.
        $receiver->forceFill(['status' => $status, 'last_status' => $lastStatus])->save();

        return $receiver;
    }

    public function test_receiver_stats_describe_the_filtered_set(): void
    {
        $this->actingAsAdmin();

        $this->receiver('a@example.test', UniOneReceiver::STATUS_ACTIVE);
        $this->receiver('b@example.test', UniOneReceiver::STATUS_ACTIVE);
        $this->receiver('c@example.test', UniOneReceiver::STATUS_BOUNCED);

        // Unfiltered: the whole list.
        $this->getJson('/api/v1/admin/unione/receivers/stats')
            ->assertOk()
            ->assertJsonPath('data.total', 3);

        // Filtered: the slice the table is showing.
        $filtered = $this->getJson('/api/v1/admin/unione/receivers/stats?status=' . UniOneReceiver::STATUS_BOUNCED)
            ->assertOk();

        $filtered->assertJsonPath('data.total', 1);
        $this->assertSame(
            1,
            $filtered->json('data.by_status.' . UniOneReceiver::STATUS_BOUNCED),
            'the per-status breakdown is counted over the same filtered set',
        );
    }

    public function test_the_stats_total_matches_the_listing_total_under_the_same_filter(): void
    {
        // The two numbers are what makes the header trustworthy: they are
        // computed by different queries and must never disagree.
        $this->actingAsAdmin();

        $this->receiver('a@example.test', UniOneReceiver::STATUS_ACTIVE);
        $this->receiver('b@example.test', UniOneReceiver::STATUS_BOUNCED);
        $this->receiver('c@example.test', UniOneReceiver::STATUS_BOUNCED);

        $query = '?status=' . UniOneReceiver::STATUS_BOUNCED;

        // The listing is simple-paginated; its total — what the paginator is
        // drawn from — is the dedicated count, which must agree with both the
        // rows and the stats header.
        $listing = $this->getJson('/api/v1/admin/unione/receivers' . $query)->assertOk();
        $count = $this->getJson('/api/v1/admin/unione/receivers/count' . $query)->assertOk();
        $stats = $this->getJson('/api/v1/admin/unione/receivers/stats' . $query)->assertOk();

        $this->assertCount(2, $listing->json('data'));
        $this->assertSame(2, $count->json('total'));
        $this->assertSame($count->json('total'), $stats->json('data.total'));
    }

    public function test_the_last_status_options_stay_whole_under_a_filter(): void
    {
        $this->actingAsAdmin();

        $this->receiver('a@example.test', UniOneReceiver::STATUS_ACTIVE, 'delivered');
        $this->receiver('b@example.test', UniOneReceiver::STATUS_BOUNCED, 'hard_bounced');

        $options = $this->getJson('/api/v1/admin/unione/receivers/stats?status=' . UniOneReceiver::STATUS_ACTIVE)
            ->assertOk()
            ->json('data.last_statuses');

        $this->assertContains('delivered', $options);
        $this->assertContains(
            'hard_bounced',
            $options,
            'the dropdown must keep every option, or choosing one removes the way back',
        );
    }

    public function test_the_members_listing_reports_the_filtered_total(): void
    {
        /*
         * The Users screen's listing is simple-paginated, so its badge and
         * paginator both read `forum-members/count`. That count has to apply
         * the listing's filters, and the listing must not run a COUNT itself.
         */
        $this->actingAsAdmin();
        $site = Site::factory()->create();

        // `status`, `role` and `slug` are deliberately NOT fillable — a
        // registration POST must not be able to set them — so the admin path
        // and this test write them with forceFill, exactly as the model
        // intends.
        ForumUser::create([
            'site_id' => $site->id, 'display_name' => 'Ann',
            'email' => 'ann@example.test', 'password' => bcrypt('secret'),
        ])->forceFill(['slug' => 'ann', 'status' => ForumUser::STATUS_ACTIVE])->save();

        ForumUser::create([
            'site_id' => $site->id, 'display_name' => 'Bob',
            'email' => 'bob@example.test', 'password' => bcrypt('secret'),
        ])->forceFill(['slug' => 'bob', 'status' => ForumUser::STATUS_BANNED])->save();

        $this->getJson('/api/v1/admin/forum-members/count')
            ->assertOk()
            ->assertExactJson(['total' => 2]);

        $this->getJson('/api/v1/admin/forum-members/count?status=' . ForumUser::STATUS_BANNED)
            ->assertOk()
            ->assertExactJson(['total' => 1]);

        DB::enableQueryLog();
        $this->getJson('/api/v1/admin/forum-members?per_page=5')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissingPath('meta.total');
        $counts = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains(strtolower($q['query']), 'count('));
        $this->assertSame([], array_values($counts), 'the listing must leave counting to forum-members/count');
    }
}
