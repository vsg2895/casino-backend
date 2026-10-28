<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The admin listings that are simple-paginated, and the COUNT beside each.
 *
 * Each of these screens calls `<listing>/count` with every reload and sizes its
 * paginator from that number, so the listing itself must not count as well —
 * that was the second COUNT per page view this contract removes. Pinned in one
 * place so a listing quietly switched back to paginate() fails here by name.
 */
class SimplePaginatedListingsTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function listings(): array
    {
        return [
            'promotion history'          => ['/api/v1/admin/promotion-history'],
            'newsletters'                => ['/api/v1/admin/newsletters'],
            'unsubscribes'               => ['/api/v1/admin/unsubscribes'],
            'verify-email history'       => ['/api/v1/admin/verify-email-sends'],
            'after-verification history' => ['/api/v1/admin/verification-promotion/history'],
            'mailgun receivers'          => ['/api/v1/admin/mailgun-receivers'],
            'warmup list'                => ['/api/v1/admin/warmup-emails'],
            'warmup history'             => ['/api/v1/admin/warmup-emails/history'],
            'phone numbers'              => ['/api/v1/admin/newsletter-phones'],
            'sms history'                => ['/api/v1/admin/newsletter-phones/history'],
            'email validation log'       => ['/api/v1/admin/email-validation'],
            'forum members'              => ['/api/v1/admin/forum-members'],
            'casinos'                    => ['/api/v1/admin/casinos'],
            'reviews'                    => ['/api/v1/admin/reviews'],
            'schedules'                  => ['/api/v1/admin/schedules'],
            'cms pages'                  => ['/api/v1/admin/pages'],
            'forum moderation queue'     => ['/api/v1/admin/forum-posts'],
            'forum member posts'         => ['/api/v1/admin/forum-member-posts'],
            'unione sends'               => ['/api/v1/admin/unione/sends'],
            'unione receivers'           => ['/api/v1/admin/unione/receivers'],
            // `{site}` is replaced with a real site id at run time.
            'site articles'              => ['/api/v1/admin/sites/{site}/articles'],
            'site forum discussions'     => ['/api/v1/admin/sites/{site}/forum-articles'],
            'site cache health'          => ['/api/v1/admin/sites/{site}/revalidations'],
        ];
    }

    #[DataProvider('listings')]
    public function test_the_listing_runs_no_count_and_its_count_endpoint_answers(string $url): void
    {
        // super-admin because CMS pages sit behind CmsPagePolicy; every other
        // listing only needs a signed-in admin.
        $this->actingAsAdmin()->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));
        [$site] = $this->siteWithKey();
        $url = str_replace('{site}', (string) $site->id, $url);

        // First the statement(s) the count endpoint runs…
        DB::enableQueryLog();
        $this->getJson("{$url}/count")
            ->assertOk()
            ->assertJsonPath('total', 0);
        // Only its COUNT statements: the endpoint also resolves `{site}`, and
        // the listing runs that same lookup legitimately.
        $countSql = array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql): bool => str_contains(strtolower($sql), 'count('),
        ));
        $this->assertNotSame([], $countSql, "{$url}/count must run a COUNT");
        DB::flushQueryLog();

        // …then the listing, which must not run that same COUNT again. Matching
        // the exact statement rather than any `count(`: some listings carry a
        // per-row `withCount` subquery or editorial counters, which are not the
        // pagination total.
        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonMissingPath('meta.total')
            ->assertJsonMissingPath('meta.last_page');

        $repeated = array_intersect(array_column(DB::getQueryLog(), 'query'), $countSql);
        $this->assertSame([], array_values($repeated), "{$url} must leave counting to {$url}/count");
    }

    public function test_a_members_posts_drawer_runs_no_count(): void
    {
        // The Users screen's drawer shows the latest page and no total, so it
        // has no count endpoint and must not count either.
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $member = ForumUser::create([
            'site_id' => $site->id, 'display_name' => 'Ann',
            'email' => 'ann@example.test', 'password' => bcrypt('secret'),
        ]);

        DB::enableQueryLog();
        $this->getJson("/api/v1/admin/forum-members/{$member->id}/posts")
            ->assertOk()
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissingPath('meta.total');

        $counts = array_filter(
            DB::getQueryLog(),
            fn (array $q): bool => str_contains(strtolower($q['query']), 'count('),
        );
        $this->assertSame([], array_values($counts));
    }
}
