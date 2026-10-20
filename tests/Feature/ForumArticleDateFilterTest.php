<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumSection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The discussion listing's date window means the OPERATOR'S day.
 *
 * The same defect the UniOne receivers screen had — `created_at` is UTC, the
 * picker hands back a local calendar day, and the server compared the two
 * directly — so an operator east of Greenwich lost the first hours of their own
 * day out of every window while the table still displayed those rows.
 *
 * This screen also carried a SECOND, worse bug on the client: it sent
 * `toISOString().slice(0, 10)`, which converts to UTC before taking the date,
 * so a picker set to the 20th transmitted "2026-10-19" and filtered the wrong
 * day outright. That half is fixed in the admin (utils/dayFilters); what is
 * asserted here is the server contract it now depends on.
 *
 * @see UniOneReceiverDateFilterTest for the same rule on the other screen.
 */
class ForumArticleDateFilterTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** UTC+3 in October 2026 — EEST runs until the 25th. */
    private const string TZ = 'Europe/Tallinn';

    private Site $site;

    private ForumCategory $category;

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
    }

    /**
     * Posted at 21:30 UTC on the 19th — 00:30 on the 20th for the operator.
     * The whole bug lives in that three-hour strip.
     */
    private function lateEveningArticle(): ForumArticle
    {
        $article = ForumArticle::create([
            'site_id'           => $this->site->id,
            'forum_category_id' => $this->category->id,
            'user_id'           => User::factory()->create()->id,
            'title'             => 'Posted just after local midnight',
            'body'              => '<p>Body.</p>',
            'status'            => ForumArticle::STATUS_PUBLISHED,
        ]);

        // `created_at` is managed by Eloquent, so it is written past the model.
        ForumArticle::whereKey($article->id)
            ->update(['created_at' => Carbon::parse('2026-10-19 21:30:00', 'UTC')]);

        return $article->refresh();
    }

    private function total(array $query): int
    {
        $url = "/api/v1/admin/sites/{$this->site->id}/forum-articles?" . http_build_query($query);

        return (int) $this->getJson($url)->assertOk()->json('meta.total');
    }

    public function test_a_post_after_local_midnight_belongs_to_the_operators_day(): void
    {
        $this->actingAsAdmin();
        $this->lateEveningArticle();

        $this->assertSame(
            1,
            $this->total(['from' => '2026-10-20', 'tz' => self::TZ]),
            'the operator sees this dated 20 Oct, so their filter for the 20th must find it',
        );
    }

    public function test_it_is_no_longer_counted_in_the_previous_local_day(): void
    {
        $this->actingAsAdmin();
        $this->lateEveningArticle();

        $this->assertSame(
            0,
            $this->total(['to' => '2026-10-19', 'tz' => self::TZ]),
            'in the operator\'s zone this was posted on the 20th',
        );
    }

    public function test_a_single_day_window_includes_its_own_last_second(): void
    {
        $this->actingAsAdmin();
        $this->lateEveningArticle();

        $this->assertSame(1, $this->total(['from' => '2026-10-20', 'to' => '2026-10-20', 'tz' => self::TZ]));
    }

    public function test_without_a_timezone_the_bounds_stay_utc(): void
    {
        // The fallback is what keeps every existing caller behaving as before.
        $this->actingAsAdmin();
        $this->lateEveningArticle();

        $this->assertSame(1, $this->total(['from' => '2026-10-19']));
        $this->assertSame(0, $this->total(['from' => '2026-10-20']));
    }

    public function test_an_unrecognised_timezone_is_rejected_rather_than_ignored(): void
    {
        $this->actingAsAdmin();

        $url = "/api/v1/admin/sites/{$this->site->id}/forum-articles?"
            . http_build_query(['from' => '2026-10-20', 'tz' => 'Mars/Olympus']);

        $this->getJson($url)->assertStatus(422)->assertJsonValidationErrors('tz');
    }
}
