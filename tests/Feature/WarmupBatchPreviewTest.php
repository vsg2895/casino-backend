<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * "Preview batch" for warmup — which addresses a run would take.
 *
 * The contract worth defending: the preview walks the SAME selection the send
 * walks, so a list shown here is the list that gets mailed, in that order. A
 * preview that could disagree with the send is worse than no preview, because
 * it invites the operator to approve one audience and mail another.
 *
 * And it must WRITE NOTHING. No cooldown stamp, no history row, no run. A
 * preview that touched `last_sent_at` would quietly retire the very addresses
 * the operator was inspecting.
 */
class WarmupBatchPreviewTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function address(string $email, ?string $createdAt = null, ?string $lastSentAt = null): WarmupEmail
    {
        $row = WarmupEmail::create(['email' => $email]);
        $row->forceFill([
            'created_at'   => $createdAt === null ? Carbon::now() : Carbon::parse($createdAt),
            'last_sent_at' => $lastSentAt === null ? null : Carbon::parse($lastSentAt),
        ])->save();

        return $row->refresh();
    }

    private function preview(array $params = [])
    {
        return $this->getJson('/api/v1/admin/warmup-emails/recipients/preview?' . http_build_query($params));
    }

    public function test_it_lists_the_addresses_a_run_would_take_newest_first(): void
    {
        $this->actingAsAdmin();
        $this->address('oldest@example.com', '2026-09-01 09:00:00');
        $this->address('middle@example.com', '2026-09-10 09:00:00');
        $this->address('newest@example.com', '2026-09-20 09:00:00');

        $res = $this->preview()->assertOk();

        // Most recently added first — the order the send itself walks.
        $this->assertSame(
            ['newest@example.com', 'middle@example.com', 'oldest@example.com'],
            array_column($res->json('data'), 'email'),
        );
        $res->assertJsonPath('meta.eligible_count', 3);
        $res->assertJsonPath('meta.would_reach', 3);
        $res->assertJsonPath('meta.truncated', false);
    }

    public function test_it_honours_the_requested_count(): void
    {
        $this->actingAsAdmin();
        foreach (range(1, 5) as $i) {
            $this->address("seed{$i}@example.com", '2026-09-0' . $i . ' 09:00:00');
        }

        $res = $this->preview(['count' => 2])->assertOk();

        $res->assertJsonCount(2, 'data');
        $res->assertJsonPath('meta.would_reach', 2);
        $res->assertJsonPath('meta.count', 2);
    }

    public function test_the_cooldown_excludes_recently_contacted_addresses(): void
    {
        // The same filter the send applies — an address inside the cooldown is
        // not mailed, so it must not appear in a list titled "who gets this".
        $this->actingAsAdmin();
        $this->address('cold@example.com', '2026-09-01 09:00:00');
        $this->address('warm@example.com', '2026-09-02 09:00:00', '2026-09-29 09:00:00');

        $res = $this->preview(['cooldown_days' => 7])->assertOk();

        $this->assertSame(['cold@example.com'], array_column($res->json('data'), 'email'));
        $res->assertJsonPath('meta.eligible_count', 1);
        $res->assertJsonPath('meta.cooldown_days', 7);
    }

    public function test_the_preview_matches_what_the_counts_endpoint_promises(): void
    {
        // Two endpoints, one selection. If these ever disagree the dialog shows
        // a number beside a list that contradicts it.
        $this->actingAsAdmin();
        foreach (range(1, 4) as $i) {
            $this->address("seed{$i}@example.com", '2026-09-0' . $i . ' 09:00:00');
        }
        $this->address('warm@example.com', '2026-09-05 09:00:00', '2026-09-30 08:00:00');

        $counts = $this->getJson('/api/v1/admin/warmup-emails/recipients?cooldown_days=3')->assertOk();
        $preview = $this->preview(['cooldown_days' => 3])->assertOk();

        $this->assertSame($counts->json('data.eligible'), $preview->json('meta.eligible_count'));
        $this->assertSame($counts->json('data.recipients'), $preview->json('meta.would_reach'));
        $this->assertCount($counts->json('data.recipients'), $preview->json('data'));
    }

    public function test_it_writes_nothing(): void
    {
        $this->actingAsAdmin();
        $this->address('seed@example.com', '2026-09-01 09:00:00');

        $this->preview()->assertOk();

        $this->assertNull(WarmupEmail::sole()->last_sent_at, 'a preview must not start a cooldown');
        $this->assertSame(0, WarmupSendRecipient::count(), 'a preview must not write history');
        $this->assertSame(0, WarmupSend::count(), 'a preview must not create a run');
    }

    public function test_it_requires_an_authenticated_admin(): void
    {
        $this->address('seed@example.com');

        $this->preview()->assertUnauthorized();
    }
}
