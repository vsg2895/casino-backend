<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Site;
use App\Models\VerificationPromotionSend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The post-verification promotion's send history.
 *
 * Shaped after the Warmup History screen, and tested for the same things: that
 * the listing and the COUNT describe the SAME filtered set (the screen holds one
 * page, so a count taken from the page would be wrong the moment there are two),
 * that every filter narrows to what it names, and that the record outlives the
 * subscriber it describes.
 */
class VerificationPromotionHistoryTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 12:00:00');
        [$this->site] = $this->siteWithKey();
        $this->actingAsAdmin();
    }

    private function send(
        string $email,
        string $status = VerificationPromotionSend::STATUS_SUCCESS,
        string $sentAt = '2026-10-12 10:00:00',
        ?Site $site = null,
        ?Newsletter $newsletter = null,
    ): VerificationPromotionSend {
        return VerificationPromotionSend::create([
            'site_id'       => ($site ?? $this->site)->id,
            'newsletter_id' => $newsletter?->id,
            'email'         => $email,
            'status'        => $status,
            'error'         => $status === VerificationPromotionSend::STATUS_FAILED ? '550 mailbox unavailable' : null,
            'sent_at'       => Carbon::parse($sentAt),
        ]);
    }

    /** @param  array<string, mixed>  $query */
    private function history(array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/admin/verification-promotion/history?' . http_build_query($query));
    }

    public function test_it_lists_attempts_newest_first_with_the_site(): void
    {
        $this->send('older@example.com', sentAt: '2026-10-01 10:00:00');
        $this->send('newer@example.com', sentAt: '2026-10-11 10:00:00');

        $res = $this->history()->assertOk();

        $this->assertSame(
            ['newer@example.com', 'older@example.com'],
            array_column($res->json('data'), 'email'),
        );
        $this->assertSame($this->site->name, $res->json('data.0.site.name'));
        $this->assertSame('success', $res->json('data.0.status'));
    }

    public function test_the_count_describes_the_filtered_set_not_the_page(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->send("person{$i}@example.com", sentAt: '2026-10-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' 10:00:00');
        }
        $this->send('failed@example.com', VerificationPromotionSend::STATUS_FAILED);

        $this->history(['per_page' => 10])->assertOk()->assertJsonCount(10, 'data');

        $this->getJson('/api/v1/admin/verification-promotion/history/count')
            ->assertOk()
            ->assertJsonPath('total', 31)
            // The banner's "did not arrive" number, over the whole set.
            ->assertJsonPath('failed', 1);
    }

    public function test_every_filter_narrows_to_what_it_names(): void
    {
        [$other] = $this->siteWithKey();

        $this->send('alice@example.com');
        $this->send('alan@example.com', VerificationPromotionSend::STATUS_FAILED);
        $this->send('bob@example.com', sentAt: '2026-09-01 10:00:00');
        $this->send('elsewhere@example.com', site: $other);

        // Address prefix.
        $this->assertSame(
            ['alan@example.com', 'alice@example.com'],
            collect($this->history(['search' => 'al'])->json('data'))->pluck('email')->sort()->values()->all(),
        );

        // Status.
        $this->assertSame(
            ['alan@example.com'],
            array_column($this->history(['status' => 'failed'])->json('data'), 'email'),
        );

        // Site.
        $this->assertSame(
            ['elsewhere@example.com'],
            array_column($this->history(['site_id' => $other->id])->json('data'), 'email'),
        );

        // Date range, inclusive at both ends.
        $this->assertSame(
            ['bob@example.com'],
            array_column($this->history(['sent_to' => '2026-09-01'])->json('data'), 'email'),
        );
        $this->assertCount(3, $this->history(['sent_from' => '2026-10-12'])->json('data'));
    }

    public function test_a_nonsense_filter_is_ignored_rather_than_fatal(): void
    {
        /*
         * These values come straight from a URL anyone can type. `?site_id[]=1`
         * hands an array to a scope, and an unparseable date is a stale
         * bookmark — neither may 500 a read-only listing.
         */
        $this->send('alice@example.com');

        $this->getJson('/api/v1/admin/verification-promotion/history?site_id[]=1')->assertOk();
        $this->history(['sent_from' => 'not-a-date'])->assertOk()->assertJsonCount(1, 'data');
        $this->history(['status' => 'banana'])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_record_outlives_the_subscriber(): void
    {
        // The FK nulls rather than cascades, and `email` is denormalised, so a
        // deleted subscriber leaves a readable row instead of a hole.
        $newsletter = Newsletter::create([
            'site_id' => $this->site->id,
            'email'   => 'gone@example.com',
        ]);

        $this->send('gone@example.com', newsletter: $newsletter);

        $newsletter->forceDelete();

        $res = $this->history()->assertOk();

        $this->assertSame('gone@example.com', $res->json('data.0.email'));
        $this->assertNull($res->json('data.0.newsletter_id'));
    }

    public function test_a_failed_attempt_carries_its_error(): void
    {
        $this->send('bounced@example.com', VerificationPromotionSend::STATUS_FAILED);

        $res = $this->history(['status' => 'failed'])->assertOk();

        $this->assertStringContainsString('550', (string) $res->json('data.0.error'));
    }

    // ── one send, one tab ────────────────────────────────────────────────────

    public function test_an_after_verification_send_is_listed_here_and_not_under_promotion(): void
    {
        /*
         * THE BUG THIS GUARDS. This stream writes to the shared promotion
         * history as well as to its own log, because the 24-hour dedup lives on
         * that shared table and has to see these sends. The side effect was
         * that every one of them appeared under BOTH tabs of a section whose
         * premise is one tab per stream.
         */
        PromotionEmailHistory::recordAttempts(
            $this->site->id,
            ['player@example.test' => PromotionEmailHistory::STATUS_SUCCESS],
            [],
            PromotionEmailHistory::STREAM_POST_VERIFICATION,
        );
        $this->send('player@example.test');

        // Its own tab shows it…
        $this->history()->assertOk()->assertJsonPath('data.0.email', 'player@example.test');

        // …and the Promotion tab does not, listing or count.
        $promotion = $this->getJson('/api/v1/admin/promotion-history')->assertOk();
        $this->assertSame([], $promotion->json('data'), 'an after-verification send must not appear under Promotion');
        $this->getJson('/api/v1/admin/promotion-history/count')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_a_campaign_send_is_still_listed_under_promotion(): void
    {
        // The other half of the rule: narrowing the tab must not empty it.
        PromotionEmailHistory::recordAttempts(
            $this->site->id,
            ['player@example.test' => PromotionEmailHistory::STATUS_SUCCESS],
        );

        $this->getJson('/api/v1/admin/promotion-history')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'player@example.test');
    }

    public function test_the_shared_dedup_still_sees_a_hidden_after_verification_send(): void
    {
        /*
         * The reason the row is written to the shared table at all. Hiding it
         * from one screen must not hide it from the rule that stops a campaign
         * mailing the same person on the same day.
         */
        PromotionEmailHistory::recordAttempts(
            $this->site->id,
            ['player@example.test' => PromotionEmailHistory::STATUS_SUCCESS],
            [],
            PromotionEmailHistory::STREAM_POST_VERIFICATION,
        );

        $this->assertSame(
            ['player@example.test'],
            PromotionEmailHistory::sentWithinDayAmong($this->site->id, ['player@example.test']),
            'the dedup must still see the send the Promotion tab hides',
        );
    }

    public function test_the_history_requires_an_admin(): void
    {
        $this->send('alice@example.com');

        // A fresh guest: the section is behind auth like every other admin route.
        auth()->forgetGuards();

        $this->getJson('/api/v1/admin/verification-promotion/history')->assertUnauthorized();
    }
}
