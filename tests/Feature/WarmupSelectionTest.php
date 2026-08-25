<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WarmupEmail;
use App\Services\WarmupRecipientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Who a warmup run contacts.
 *
 * Selection has two independent halves and this file pins both:
 *
 *  1. ORDER  — most recently ADDED first (`created_at DESC, id DESC`).
 *  2. FILTER — skip anything successfully contacted inside the cooldown window.
 *
 * Half 2 is what makes half 1 safe: recency alone would hand every run to the
 * same head of the list. These tests exist because that pairing is the entire
 * behaviour of the feature, and either half silently inverting (an `asc` for a
 * `desc`, a `<` for a `<=`) would still "work" while mailing the wrong people.
 *
 * Nothing here sends: only {@see WarmupRecipientService} is exercised.
 * The database is the in-memory SQLite from phpunit.xml.
 */
class WarmupSelectionTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private WarmupRecipientService $recipients;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recipients = app(WarmupRecipientService::class);
        // Every relative timestamp below is anchored to this instant, so a test
        // running across midnight cannot drift into a different day bucket.
        Carbon::setTestNow('2026-08-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Timestamps are the subject under test here, so they are set explicitly
     * rather than left to model events.
     */
    private function addAddress(string $email, string $addedAt, ?string $lastSentAt = null): WarmupEmail
    {
        $row = WarmupEmail::create(['email' => $email]);

        $row->forceFill([
            'created_at'   => Carbon::parse($addedAt),
            'updated_at'   => Carbon::parse($addedAt),
            'last_sent_at' => $lastSentAt === null ? null : Carbon::parse($lastSentAt),
        ])->save();

        return $row->refresh();
    }

    /** The addresses a run would contact, in the order it would contact them. */
    private function selected(?int $limit, ?int $cooldownDays = null): array
    {
        $picked = [];

        $this->recipients->eachChunk(
            $limit,
            $cooldownDays,
            WarmupRecipientService::STREAM_CHUNK,
            function ($rows) use (&$picked): void {
                foreach ($rows as $row) {
                    $picked[] = $row->email;
                }
            },
        );

        return $picked;
    }

    // ── 1. Order ─────────────────────────────────────────────────────────────

    public function test_a_limited_run_takes_the_most_recently_added_first(): void
    {
        $this->addAddress('oldest@example.com', '2026-08-01 09:00:00');
        $this->addAddress('middle@example.com', '2026-08-10 09:00:00');
        $this->addAddress('newest@example.com', '2026-08-20 09:00:00');

        $this->assertSame(
            ['newest@example.com', 'middle@example.com'],
            $this->selected(limit: 2),
        );
    }

    public function test_order_is_recency_not_insertion_id(): void
    {
        // Inserted last, but added to the list first — id order and created_at
        // order disagree, which is exactly what an import backfill looks like.
        $this->addAddress('recent@example.com', '2026-08-20 09:00:00');
        $this->addAddress('ancient@example.com', '2026-01-01 09:00:00');

        $this->assertSame(['recent@example.com'], $this->selected(limit: 1));
    }

    // ── 2. Cooldown filter ───────────────────────────────────────────────────

    public function test_cooldown_skips_addresses_contacted_inside_the_window(): void
    {
        // Newest, but warmed yesterday — must be skipped by a 7-day cooldown.
        $this->addAddress('warmed@example.com', '2026-08-26 09:00:00', '2026-08-26 10:00:00');
        $this->addAddress('cold@example.com', '2026-08-20 09:00:00', '2026-07-01 10:00:00');

        $this->assertSame(['cold@example.com'], $this->selected(limit: 10, cooldownDays: 7));
    }

    public function test_never_contacted_addresses_are_always_eligible(): void
    {
        // NULL last_sent_at must survive the filter. A plain `<=` comparison
        // would drop these rows and make a fresh import ineligible for its own
        // first send — the worst possible failure for this feature.
        $this->addAddress('fresh@example.com', '2026-08-26 09:00:00', null);
        $this->addAddress('warmed@example.com', '2026-08-25 09:00:00', '2026-08-27 09:00:00');

        $this->assertSame(
            ['fresh@example.com'],
            $this->selected(limit: 10, cooldownDays: 365),
        );
    }

    public function test_cooldown_boundary_is_inclusive(): void
    {
        // Contacted exactly the cooldown ago: the window has elapsed, so this
        // address is due again. Pins `<=` against an off-by-one `<`.
        $this->addAddress('boundary@example.com', '2026-08-01 09:00:00', '2026-08-20 12:00:00');

        $this->assertSame(['boundary@example.com'], $this->selected(limit: 10, cooldownDays: 7));
        // One day short of the window: still cooling down.
        $this->assertSame([], $this->selected(limit: 10, cooldownDays: 8));
    }

    public function test_send_to_everyone_ignores_the_cooldown(): void
    {
        // "Send to every address" and "skip recently contacted" are contradictory
        // instructions; the explicit choice wins and no cooldown is applied.
        $this->addAddress('a@example.com', '2026-08-26 09:00:00', '2026-08-27 11:00:00');
        $this->addAddress('b@example.com', '2026-08-25 09:00:00', '2026-08-27 11:00:00');

        $this->assertCount(2, $this->selected(limit: null, cooldownDays: null));
    }

    // ── 3. Capping ───────────────────────────────────────────────────────────

    public function test_requesting_more_than_eligible_contacts_only_the_eligible(): void
    {
        // Asking for 50 with 2 eligible is an ordinary request, not an error.
        $this->addAddress('a@example.com', '2026-08-26 09:00:00');
        $this->addAddress('b@example.com', '2026-08-25 09:00:00');
        $this->addAddress('warmed@example.com', '2026-08-24 09:00:00', '2026-08-27 09:00:00');

        $picked = $this->selected(limit: 50, cooldownDays: 7);

        $this->assertCount(2, $picked);
        $this->assertNotContains('warmed@example.com', $picked);
        $this->assertSame(2, $this->recipients->count(50, 7));
    }

    public function test_count_reports_the_eligible_set_not_the_list_size(): void
    {
        $this->addAddress('cold@example.com', '2026-08-01 09:00:00');
        $this->addAddress('warmed@example.com', '2026-08-26 09:00:00', '2026-08-27 09:00:00');

        // available() is the whole list; eligible()/count() respect the cooldown.
        $this->assertSame(2, $this->recipients->available());
        $this->assertSame(1, $this->recipients->eligible(7));
        $this->assertSame(1, $this->recipients->count(null, 7));
        $this->assertSame(2, $this->recipients->count(null, null));
    }

    // ── 4. Keyset traversal ──────────────────────────────────────────────────

    public function test_selection_never_repeats_or_skips_when_created_at_ties(): void
    {
        // One import stamps every row with an identical created_at. Without the
        // `id` tiebreaker in both the ORDER BY and the keyset cursor, a chunked
        // read repeats some rows and skips others.
        for ($i = 1; $i <= 25; $i++) {
            $this->addAddress("bulk{$i}@example.com", '2026-08-20 09:00:00');
        }

        $picked = [];
        $this->recipients->eachChunk(null, null, 5, function ($rows) use (&$picked): void {
            foreach ($rows as $row) {
                $picked[] = $row->email;
            }
        });

        $this->assertCount(25, $picked, 'every address should be contacted exactly once');
        $this->assertCount(25, array_unique($picked), 'no address should be repeated');
    }

    public function test_limit_is_respected_across_chunk_boundaries(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->addAddress("bulk{$i}@example.com", '2026-08-20 09:00:00');
        }

        $picked = [];
        $this->recipients->eachChunk(7, null, 3, function ($rows) use (&$picked): void {
            foreach ($rows as $row) {
                $picked[] = $row->email;
            }
        });

        $this->assertCount(7, $picked);
    }

    // ── 5. The preview promises what the send delivers ───────────────────────

    public function test_recipients_preview_matches_the_selection(): void
    {
        $this->actingAsAdmin();

        $this->addAddress('cold1@example.com', '2026-08-26 09:00:00');
        $this->addAddress('cold2@example.com', '2026-08-25 09:00:00');
        $this->addAddress('warmed@example.com', '2026-08-24 09:00:00', '2026-08-27 09:00:00');

        $this->getJson('/api/v1/admin/warmup-emails/recipients?count=10&cooldown_days=7')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.eligible', 2)
            // What the run would actually reach — and what the dialog shows.
            ->assertJsonPath('data.recipients', 2)
            ->assertJsonPath('data.min_cooldown_days', 1)
            ->assertJsonPath('data.max_cooldown_days', 365);

        $this->assertSame(2, count($this->selected(10, 7)));
    }

    public function test_preview_without_a_cooldown_reports_the_whole_list(): void
    {
        $this->actingAsAdmin();

        $this->addAddress('a@example.com', '2026-08-26 09:00:00', '2026-08-27 09:00:00');
        $this->addAddress('b@example.com', '2026-08-25 09:00:00', '2026-08-27 09:00:00');

        $this->getJson('/api/v1/admin/warmup-emails/recipients')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.eligible', 2)
            ->assertJsonPath('data.recipients', 2);
    }
}
