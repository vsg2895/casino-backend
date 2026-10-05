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

    private function newsFor(int $siteId, string $title, string $publishedAt, int $position = 0): Article
    {
        return Article::create([
            'site_id'      => $siteId,
            'type'         => Article::TYPE_NEWS,
            'title'        => $title,
            'slug'         => str($title)->slug()->value(),
            'position'     => $position,
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
        $res->assertJsonPath('meta.total', 20);
        $res->assertJsonPath('meta.last_page', 4);
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
            'position'     => 0,
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
            'position'     => 0,
            'active'       => true,
        ])->assertOk();

        $this->assertSame(
            ['Evening', 'Morning', 'Midday'],
            $this->titles($site->id),
            'midnight sorts below every other entry published that day',
        );
    }
}
