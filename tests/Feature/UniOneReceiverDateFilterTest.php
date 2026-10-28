<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\UniOne\UniOneReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The date filters on the UniOne receivers screen mean the OPERATOR'S day.
 *
 * ── The bug ─────────────────────────────────────────────────────────────────
 *
 * `last_sent_at` is stored in UTC. The date picker hands back a calendar day in
 * the operator's own zone, and the server used to compare that string directly
 * — so "2026-10-20" became UTC midnight, and for an operator in UTC+3 the first
 * three hours of their 20th fell outside their own filter.
 *
 * It showed up as a screen contradicting itself: mail handed over at 21:30 UTC
 * on the 19th renders as "20 Oct" in the table (the column is formatted in
 * local time), while filtering for the 20th returned nothing and filtering for
 * the 19th returned everything.
 *
 * Both halves are asserted below, because fixing only the filter would have
 * swapped which half was wrong.
 */
class UniOneReceiverDateFilterTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** UTC+3 in October 2026 — EEST runs until the 25th. */
    private const string TZ = 'Europe/Tallinn';

    private const string ENDPOINT = '/api/v1/admin/unione/receivers';

    /**
     * Handed over at 21:30 UTC on the 19th — which is 00:30 on the 20th for the
     * operator. The whole bug lives in that three-hour strip.
     */
    private function lateEveningSend(): UniOneReceiver
    {
        $receiver = UniOneReceiver::create(['email' => 'late@example.test']);

        $receiver->forceFill([
            'last_sent_at' => Carbon::parse('2026-10-19 21:30:00', 'UTC'),
            'created_at'   => Carbon::parse('2026-10-19 21:30:00', 'UTC'),
            'last_status'  => 'accepted',
            'send_count'   => 1,
        ])->save();

        return $receiver->refresh();
    }

    /**
     * Rows the listing returns for this window, checked against its count.
     *
     * The listing is simple-paginated and carries no `meta.total`; the screen
     * sizes itself from `/count`. Both apply the window, so both are asserted.
     */
    private function total(array $query): int
    {
        $qs = http_build_query($query);
        $rows = count($this->getJson(self::ENDPOINT . '?' . $qs)->assertOk()->json('data'));
        $counted = (int) $this->getJson(self::ENDPOINT . '/count?' . $qs)->assertOk()->json('total');

        $this->assertSame($rows, $counted, 'the listing and its count must apply the same window');

        return $rows;
    }

    // ── The operator's day ───────────────────────────────────────────────────

    public function test_a_send_after_local_midnight_belongs_to_the_operators_day(): void
    {
        $this->actingAsAdmin();
        $this->lateEveningSend();

        $this->assertSame(
            1,
            $this->total(['sent_from' => '2026-10-20', 'tz' => self::TZ]),
            'the operator sees this row dated 20 Oct, so their filter for the 20th must find it',
        );
    }

    public function test_it_is_no_longer_counted_in_the_previous_local_day(): void
    {
        // The other half. Before the fix this returned 1 — the row answered to
        // both days at once, which is what made the screen unreadable.
        $this->actingAsAdmin();
        $this->lateEveningSend();

        $this->assertSame(
            0,
            $this->total(['sent_to' => '2026-10-19', 'tz' => self::TZ]),
            'in the operator\'s zone this send happened on the 20th, not the 19th',
        );
    }

    public function test_the_added_window_is_resolved_the_same_way(): void
    {
        // `from`/`to` filter created_at and had the identical defect.
        $this->actingAsAdmin();
        $this->lateEveningSend();

        $this->assertSame(1, $this->total(['from' => '2026-10-20', 'tz' => self::TZ]));
        $this->assertSame(0, $this->total(['to' => '2026-10-19', 'tz' => self::TZ]));
    }

    public function test_a_whole_local_day_window_contains_the_send(): void
    {
        $this->actingAsAdmin();
        $this->lateEveningSend();

        $this->assertSame(
            1,
            $this->total(['sent_from' => '2026-10-20', 'sent_to' => '2026-10-20', 'tz' => self::TZ]),
            'a single-day window must include its own last second',
        );
    }

    // ── Backwards compatibility ──────────────────────────────────────────────

    public function test_without_a_timezone_the_bounds_stay_utc(): void
    {
        /*
         * The fallback matters as much as the fix: the export link, a script or
         * a curl by hand sends no `tz`, and those callers must behave exactly as
         * they did before rather than silently shifting by the server's locale.
         */
        $this->actingAsAdmin();
        $this->lateEveningSend();

        $this->assertSame(1, $this->total(['sent_from' => '2026-10-19']), 'UTC: the send is on the 19th');
        $this->assertSame(0, $this->total(['sent_from' => '2026-10-20']), 'UTC: nothing on the 20th');
    }

    public function test_an_unrecognised_timezone_is_rejected_rather_than_ignored(): void
    {
        // Silently falling back would be defensible, but then a typo'd zone
        // would quietly return a different day's rows and look like data loss.
        $this->actingAsAdmin();

        $this->getJson(self::ENDPOINT . '?' . http_build_query(['sent_from' => '2026-10-20', 'tz' => 'Mars/Olympus']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tz');
    }

    // ── The header must agree with the rows ──────────────────────────────────

    public function test_the_stats_header_uses_the_same_window_as_the_listing(): void
    {
        /*
         * The two are separate endpoints fed by one builder. If only the listing
         * had been fixed, the count above the table would describe a different
         * day from the rows under it — the exact class of bug the filtered-count
         * work was done to remove.
         */
        $this->actingAsAdmin();
        $this->lateEveningSend();

        $query = http_build_query(['sent_from' => '2026-10-20', 'tz' => self::TZ]);

        $listing = (int) $this->getJson(self::ENDPOINT . '/count?' . $query)->assertOk()->json('total');
        $stats = (int) $this->getJson(self::ENDPOINT . '/stats?' . $query)->assertOk()->json('data.total');

        $this->assertSame(1, $listing);
        $this->assertSame($listing, $stats);
    }

    public function test_a_west_of_greenwich_operator_is_shifted_the_other_way(): void
    {
        // Proves the conversion is a real zone lookup and not a one-directional
        // fudge that happens to suit UTC+N.
        $this->actingAsAdmin();

        $receiver = UniOneReceiver::create(['email' => 'early@example.test']);
        // 02:00 UTC on the 20th = 22:00 on the 19th in New York (UTC-4).
        $receiver->forceFill(['last_sent_at' => Carbon::parse('2026-10-20 02:00:00', 'UTC')])->save();

        $this->assertSame(1, $this->total(['sent_to' => '2026-10-19', 'tz' => 'America/New_York']));
        $this->assertSame(0, $this->total(['sent_from' => '2026-10-20', 'tz' => 'America/New_York']));
    }
}
