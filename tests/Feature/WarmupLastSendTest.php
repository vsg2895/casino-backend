<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * "How many went out last time?" — the Warmup screen's opening question.
 *
 * The answer already existed in `warmup_sends`; nothing read it back. These
 * tests defend the two things that make the answer trustworthy:
 *
 *  1. The headline is what the transport ACCEPTED, not what was asked for.
 *     A run that requested 250 and sent 243 did not send 250, and a banner
 *     that says otherwise is worse than no banner.
 *
 *  2. A run still in flight is reported as in flight. Its counts are partials
 *     that keep rising, and presenting one as a total is how an operator
 *     concludes a send failed when it is merely halfway through.
 */
class WarmupLastSendTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lastSend()
    {
        return $this->getJson('/api/v1/admin/warmup-emails/last-send');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function startedRun(array $attributes = [], ?string $createdAt = null): WarmupSend
    {
        $send = WarmupSend::create(array_merge([
            'site_id'         => null,
            'user_id'         => null,
            'template'        => 'subscribe',
            'requested_count' => 250,
            'cooldown_days'   => 7,
            'queued_count'    => 250,
        ], $attributes));

        if ($createdAt !== null) {
            $send->forceFill(['created_at' => Carbon::parse($createdAt)])->save();
        }

        return $send->refresh();
    }

    private function recipients(WarmupSend $send, int $sent, int $failed = 0): void
    {
        $rows = [];

        foreach (range(1, $sent + $failed) as $i) {
            $rows[] = [
                'warmup_send_id'  => $send->id,
                'warmup_email_id' => null,
                'site_id'         => null,
                'email'           => "warm{$i}@example.com",
                'template'        => 'subscribe',
                'status'          => $i <= $sent
                    ? WarmupSendRecipient::STATUS_SENT
                    : WarmupSendRecipient::STATUS_FAILED,
                'error'           => $i <= $sent ? null : 'mailbox unavailable',
                'sent_at'         => Carbon::now(),
                'created_at'      => Carbon::now(),
                'updated_at'      => Carbon::now(),
            ];
        }

        WarmupSendRecipient::query()->insert($rows);
    }

    public function test_it_reports_null_before_any_run_has_happened(): void
    {
        $this->actingAsAdmin();

        // Not a 404: an empty history is a normal state of a working screen.
        $this->lastSend()->assertOk()->assertJsonPath('data', null);
    }

    /**
     * The scenario from the request: 250 sent today, read back tomorrow.
     */
    public function test_it_reports_yesterdays_count_the_next_morning(): void
    {
        $this->actingAsAdmin();

        $send = $this->startedRun(createdAt: '2026-10-20 14:30:00');
        $this->recipients($send, sent: 250);

        $res = $this->lastSend()->assertOk();

        $res->assertJsonPath('data.sent_count', 250);
        $res->assertJsonPath('data.queued_count', 250);
        $res->assertJsonPath('data.requested_count', 250);
        $res->assertJsonPath('data.failed_count', 0);
        // A finished run, read the following day, must not look live — the
        // staleness bound is what makes that true.
        $res->assertJsonPath('data.in_progress', false);
        $this->assertStringStartsWith('2026-10-20T14:30:00', (string) $res->json('data.started_at'));
    }

    /**
     * THE NUMBER THAT MATTERS. Requested and queued both say 250; only 243 were
     * accepted. The headline follows the transport, and the shortfall is still
     * visible rather than rounded away.
     */
    public function test_the_headline_count_is_what_was_sent_not_what_was_asked_for(): void
    {
        $this->actingAsAdmin();

        $send = $this->startedRun(createdAt: '2026-10-20 14:30:00');
        $this->recipients($send, sent: 243, failed: 7);

        $res = $this->lastSend()->assertOk();

        $res->assertJsonPath('data.sent_count', 243);
        $res->assertJsonPath('data.failed_count', 7);
        $res->assertJsonPath('data.attempted_count', 250);
        $res->assertJsonPath('data.requested_count', 250);
    }

    public function test_a_run_still_landing_is_reported_as_in_progress(): void
    {
        $this->actingAsAdmin();

        // Started a minute ago; only 40 of its 250 attempts recorded so far.
        $send = $this->startedRun(createdAt: '2026-10-21 08:59:00');
        $this->recipients($send, sent: 40);

        $res = $this->lastSend()->assertOk();

        $res->assertJsonPath('data.in_progress', true);
        // The partial is still reported — the flag is what stops it being read
        // as the total.
        $res->assertJsonPath('data.sent_count', 40);
    }

    /**
     * A fan-out that died leaves `queued_count` at 0 forever. Without a bound
     * on how long a run may claim to be in flight, that banner would say
     * "sending now" for the rest of the installation's life.
     */
    public function test_an_abandoned_run_stops_being_in_progress(): void
    {
        $this->actingAsAdmin();

        $this->startedRun(['queued_count' => 0], createdAt: '2026-10-19 10:00:00');

        $this->lastSend()->assertOk()->assertJsonPath('data.in_progress', false);
    }

    public function test_a_cancelled_run_is_never_in_progress(): void
    {
        $this->actingAsAdmin();

        $send = $this->startedRun(createdAt: '2026-10-21 08:59:00');
        $send->forceFill(['cancelled_at' => Carbon::now()])->save();
        $this->recipients($send, sent: 40);

        $res = $this->lastSend()->assertOk();

        $res->assertJsonPath('data.in_progress', false);
        $this->assertNotNull($res->json('data.cancelled_at'));
    }

    /** The LAST run, not the first — ordering is the whole feature. */
    public function test_it_reports_the_most_recent_run(): void
    {
        $this->actingAsAdmin();

        $older = $this->startedRun(['requested_count' => 100, 'queued_count' => 100], createdAt: '2026-10-18 10:00:00');
        $this->recipients($older, sent: 100);

        $newer = $this->startedRun(['requested_count' => 250, 'queued_count' => 250], createdAt: '2026-10-20 10:00:00');
        $this->recipients($newer, sent: 250);

        $this->lastSend()->assertOk()->assertJsonPath('data.sent_count', 250);
    }

    public function test_it_carries_the_context_the_banner_shows(): void
    {
        $this->actingAsAdmin();

        $site = Site::factory()->create(['name' => 'WinPalAck']);
        $send = $this->startedRun(['site_id' => $site->id], createdAt: '2026-10-20 14:30:00');
        $this->recipients($send, sent: 250);

        $res = $this->lastSend()->assertOk();

        $res->assertJsonPath('data.site_name', 'WinPalAck');
        $res->assertJsonPath('data.cooldown_days', 7);
        $res->assertJsonPath('data.template', 'subscribe');
        // Resolved through EmailTemplateCatalog, not echoed from the column.
        $this->assertNotSame('', (string) $res->json('data.template_label'));
    }

    public function test_it_requires_an_authenticated_admin(): void
    {
        $this->getJson('/api/v1/admin/warmup-emails/last-send')->assertUnauthorized();
    }

    /**
     * The route is a LITERAL segment sharing a prefix with `{warmup_email}`.
     * Declared after the apiResource it would be swallowed by model binding and
     * answer 404 — the failure this project's route convention exists to stop.
     */
    public function test_the_literal_route_is_not_swallowed_by_model_binding(): void
    {
        $this->actingAsAdmin();

        $this->lastSend()->assertOk();
    }
}
