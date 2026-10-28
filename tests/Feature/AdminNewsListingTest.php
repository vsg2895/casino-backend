<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The admin's News listing: its page size, and the stability of its order.
 *
 * TWO REPORTED BUGS, both of which looked like the server misbehaving and were
 * really about what the client sent.
 *
 *  1. The rows-per-page control snapped back to 15. The endpoint has always
 *     accepted `per_page`; the admin never sent it, so the server answered with
 *     its default and the screen copied that back into the control.
 *
 *  2. Saving an entry moved it in the list. The edit dialog's date input can
 *     only carry a DAY, so it truncated `published_at` to midnight — and the
 *     listing is ordered by that column.
 *
 * These tests pin the server half of both contracts, so the admin has something
 * stable to rely on: the size it asks for is the size it gets, and an update
 * that preserves `published_at` preserves the row's place.
 */
class AdminNewsListingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function newsFor(int $siteId, string $title, string $publishedAt): Article
    {
        return Article::create([
            'site_id'      => $siteId,
            'type'         => Article::TYPE_NEWS,
            'title'        => $title,
            'slug'         => str($title)->slug()->value(),
            'active'       => true,
            'published_at' => Carbon::parse($publishedAt),
        ]);
    }

    /** @return list<string> Titles in listing order. */
    private function titles(int $siteId, int $perPage = 15): array
    {
        $res = $this->getJson("/api/v1/admin/sites/{$siteId}/articles?type=news&per_page={$perPage}")->assertOk();

        return array_column($res->json('data'), 'title');
    }

    public function test_the_meta_counts_the_whole_section_not_the_page(): void
    {
        /*
         * The four numbers the screen puts above the table. Each is counted
         * server-side over the WHOLE section, because the admin only ever holds
         * one page — counting the rows in hand would be wrong the moment there
         * is a second one, which is the bug `published_count` was added to fix.
         *
         * The two rail counts are VISIBLE-and-flagged, not merely flagged: a
         * draft with the box ticked is on no rail, and counting it would send an
         * editor looking for a post the site is not showing.
         */
        [$site] = $this->siteWithKey(['news_enabled' => true]);
        $this->actingAsAdmin();

        // Live, on both rails.
        $both = $this->newsFor($site->id, 'Live and on both rails', '2026-09-20 10:00:00');
        $both->update(['to_be_most_popular' => true, 'featured' => true]);

        // Live, home strip only.
        $this->newsFor($site->id, 'Live and featured', '2026-09-19 10:00:00')->update(['featured' => true]);

        // Live, neither rail.
        $this->newsFor($site->id, 'Just live', '2026-09-18 10:00:00');

        // Switched off, yet flagged for both rails — counted in the total and
        // in nothing else.
        $hidden = $this->newsFor($site->id, 'Hidden but ticked', '2026-09-17 10:00:00');
        $hidden->update(['active' => false, 'to_be_most_popular' => true, 'featured' => true]);

        // Scheduled for the future, and flagged — same treatment: it is not
        // published, so it is on no rail.
        $future = $this->newsFor($site->id, 'Scheduled', '2099-01-01 10:00:00');
        $future->update(['to_be_most_popular' => true, 'featured' => true]);

        // A sixth row, so the section does not fit on one page: 5 is the
        // smallest page size the endpoint accepts.
        $this->newsFor($site->id, 'A plain draft', '2026-09-16 10:00:00')->update(['active' => false]);

        $res = $this->getJson("/api/v1/admin/sites/{$site->id}/articles?type=news&per_page=5")->assertOk();

        // Five rows in hand, six in the section — the counts below describe the
        // section, which is the whole point of them coming from the server.
        $this->assertCount(5, $res->json('data'));
        $res->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.published_count', 3)
            ->assertJsonPath('meta.most_popular_count', 1)
            ->assertJsonPath('meta.featured_count', 2);

        // The section's size — what sizes the paginator — is the dedicated count.
        $this->getJson("/api/v1/admin/sites/{$site->id}/articles/count?type=news")
            ->assertOk()
            ->assertExactJson(['total' => 6]);
    }

    public function test_the_counts_do_not_leak_across_sites_or_sections(): void
    {
        [$site] = $this->siteWithKey(['news_enabled' => true, 'guides_enabled' => true]);
        [$other] = $this->siteWithKey(['news_enabled' => true]);
        $this->actingAsAdmin();

        $this->newsFor($site->id, 'Ours', '2026-09-20 10:00:00')->update(['featured' => true]);
        $this->newsFor($other->id, 'Another site', '2026-09-20 10:00:00')->update(['featured' => true]);

        // A guide on the same site, flagged the same way: different section,
        // and the News screen must not count it.
        Article::create([
            'site_id'      => $site->id,
            'type'         => Article::TYPE_GUIDE,
            'title'        => 'A guide',
            'slug'         => 'a-guide',
            'active'       => true,
            'featured'     => true,
            'published_at' => Carbon::parse('2026-09-20 10:00:00'),
        ]);

        $this->getJson("/api/v1/admin/sites/{$site->id}/articles?type=news")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.published_count', 1)
            ->assertJsonPath('meta.featured_count', 1);

        $this->getJson("/api/v1/admin/sites/{$site->id}/articles/count?type=news")
            ->assertOk()
            ->assertExactJson(['total' => 1]);
    }

    public function test_a_scraped_draft_awaiting_its_rewrite_is_flagged(): void
    {
        /*
         * Until `news:rewrite` has run, a scraped row's title and excerpt are the
         * SOURCE's words. The flag is what stops an editor publishing them, and
         * it is computed in SQL because the listing never selects the body.
         */
        [$site] = $this->siteWithKey(['news_enabled' => true]);
        $this->actingAsAdmin();

        $this->newsFor($site->id, 'Scraped waiting', '2026-09-20 10:00:00')
            ->update(['source_ref' => 'feed:1', 'body' => null]);
        $this->newsFor($site->id, 'Scraped rewritten', '2026-09-19 10:00:00')
            ->update(['source_ref' => 'feed:2', 'body' => '<p>Original.</p>']);
        // Hand-written with no body yet: nothing to rewrite, so not flagged.
        $this->newsFor($site->id, 'Hand-written draft', '2026-09-18 10:00:00');

        $rows = collect($this->getJson("/api/v1/admin/sites/{$site->id}/articles?type=news")->assertOk()->json('data'))
            ->pluck('awaiting_rewrite', 'title');

        $this->assertSame([
            'Scraped waiting'    => true,
            'Scraped rewritten'  => false,
            'Hand-written draft' => false,
        ], $rows->all());
        $this->assertArrayNotHasKey('body', $this->getJson("/api/v1/admin/sites/{$site->id}/articles?type=news")->json('data.0'));
    }

    public function test_the_listing_is_newest_first(): void
    {
        // The admin list has to render in the order the public feed does, or
        // the screen an editor reads is not the page a visitor reads.
        [$site] = $this->siteWithKey(['news_enabled' => true]);
        $this->actingAsAdmin();

        $this->newsFor($site->id, 'Oldest', '2026-09-01 10:00:00');
        $this->newsFor($site->id, 'Newest', '2026-09-20 10:00:00');

        $this->assertSame(['Newest', 'Oldest'], $this->titles($site->id));
    }

    public function test_the_listing_honours_the_requested_page_size(): void
    {
        // What the rows-per-page control sends. Ignoring it is what made the
        // control snap back to 15.
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        for ($i = 1; $i <= 20; $i++) {
            $this->newsFor($site->id, "Story {$i}", '2026-09-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' 12:00:00');
        }

        $res = $this->getJson("/api/v1/admin/sites/{$site->id}/articles?type=news&per_page=5")->assertOk();

        $res->assertJsonCount(5, 'data');
        // The screen reads this back into the control, so it must echo what was
        // asked for and not the server's own default.
        $res->assertJsonPath('meta.per_page', 5);
        $res->assertJsonPath('meta.has_more', true);
    }

    public function test_the_default_page_size_is_used_when_none_is_asked_for(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        for ($i = 1; $i <= 20; $i++) {
            $this->newsFor($site->id, "Story {$i}", '2026-09-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' 12:00:00');
        }

        $this->getJson("/api/v1/admin/sites/{$site->id}/articles?type=news")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_an_edit_that_preserves_the_timestamp_preserves_the_order(): void
    {
        /*
         * The whole point of the client-side fix.
         *
         * Three entries published on the SAME DAY at different times. The list
         * orders by `published_at` descending, so the times are the only thing
         * separating them — exactly the case the date-only form destroyed.
         */
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->newsFor($site->id, 'Morning', '2026-09-28 09:00:00');
        $middle = $this->newsFor($site->id, 'Midday', '2026-09-28 13:00:00');
        $this->newsFor($site->id, 'Evening', '2026-09-28 19:00:00');

        $this->assertSame(['Evening', 'Midday', 'Morning'], $this->titles($site->id));

        // An ordinary edit: the title changes, the timestamp is sent back as it
        // was — which is what the dialog now does.
        $this->putJson("/api/v1/admin/sites/{$site->id}/articles/{$middle->id}?type=news", [
            'title'        => 'Midday, revised',
            'published_at' => $middle->published_at->toIso8601String(),
            'active'       => true,
        ])->assertOk();

        $this->assertSame(
            ['Evening', 'Midday, revised', 'Morning'],
            $this->titles($site->id),
            'an edit that keeps the timestamp must keep the row in place',
        );
    }

    public function test_truncating_the_timestamp_to_a_date_is_what_moved_the_row(): void
    {
        /*
         * The REGRESSION this documents, reproduced deliberately.
         *
         * Sending the day alone writes midnight, which drops the entry below
         * everything else published later that day. If this ever stops being
         * true the client-side fix has become unnecessary — but while it holds,
         * the dialog must keep sending the full instant.
         */
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->newsFor($site->id, 'Morning', '2026-09-28 09:00:00');
        $middle = $this->newsFor($site->id, 'Midday', '2026-09-28 13:00:00');
        $this->newsFor($site->id, 'Evening', '2026-09-28 19:00:00');

        $this->putJson("/api/v1/admin/sites/{$site->id}/articles/{$middle->id}?type=news", [
            'title'        => 'Midday',
            // What the old form sent: the day, with the time thrown away.
            'published_at' => '2026-09-28',
            'active'       => true,
        ])->assertOk();

        $this->assertSame(
            ['Evening', 'Morning', 'Midday'],
            $this->titles($site->id),
            'midnight sorts below every other entry published that day',
        );
    }
}
