<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendVerificationPromotionJob;
use App\Models\Newsletter;
use App\Models\VerificationPromotionEmail;
use App\Support\ClockFacts;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Locks the TIMING half of the post-verification promotion.
 *
 * The bug this guards against was reported as a four-hour discrepancy between
 * the admin (which renders timestamps in the VIEWER'S BROWSER timezone) and
 * laravel.log (which writes them in `config('app.timezone')`). That offset is
 * presentation only, and these tests pin down why: `verified_at` and the sweep's
 * cutoff are both produced by `Carbon::now()`, so they move together and the
 * rule "send N minutes after the click" holds in any timezone.
 *
 * Also covered: the sweep must be OBSERVABLE. Its silent failure modes — a
 * stranded `withoutOverlapping` mutex, a stopped cron — are indistinguishable
 * from "ran and found nobody" unless something is logged unconditionally.
 *
 * No mail is sent: the queue is faked and only dispatch is asserted.
 */
class VerificationPromotionTimingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function enableSection(int $delayMinutes): void
    {
        VerificationPromotionEmail::current()->update([
            'active'        => true,
            'delay_minutes' => $delayMinutes,
        ]);
    }

    /** A verified subscriber whose click happened $minutesAgo minutes ago. */
    private function subscriberVerifiedMinutesAgo(int $minutesAgo): Newsletter
    {
        [$site] = $this->siteWithKey();

        $newsletter = new Newsletter([
            'site_id'  => $site->id,
            'email'    => 'subscriber@example.test',
            'verified' => true,
        ]);
        $newsletter->save();

        Newsletter::whereKey($newsletter->id)->update([
            'verified_at' => Carbon::now()->subMinutes($minutesAgo),
        ]);

        return $newsletter->refresh();
    }

    // ── The rule holds in any application timezone ───────────────────────────

    /**
     * The heart of it. With the app running four hours off UTC — the offset the
     * admin's rendering made visible — a subscriber who verified six minutes ago
     * is still eligible under a five-minute delay, because both sides of the
     * comparison come from the same clock.
     */
    public function test_delay_is_measured_from_the_click_in_a_non_utc_timezone(): void
    {
        Queue::fake();
        config()->set('app.timezone', 'Asia/Yerevan');   // UTC+4
        date_default_timezone_set('Asia/Yerevan');

        $this->enableSection(delayMinutes: 5);
        $newsletter = $this->subscriberVerifiedMinutesAgo(6);

        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Queue::assertPushed(
            SendVerificationPromotionJob::class,
            static fn (SendVerificationPromotionJob $job): bool => true,
        );
        $this->assertSame(1, count(Queue::pushed(SendVerificationPromotionJob::class)));
        $this->assertNotNull($newsletter->refresh()->verified_at);
    }

    /**
     * The other half: the offset must not make anyone eligible EARLY either. A
     * four-hour timezone shift that leaked into the comparison would show up
     * here as a send that should still be waiting.
     */
    public function test_subscriber_is_not_eligible_before_the_delay_in_a_non_utc_timezone(): void
    {
        Queue::fake();
        config()->set('app.timezone', 'Asia/Yerevan');
        date_default_timezone_set('Asia/Yerevan');

        $this->enableSection(delayMinutes: 30);
        $this->subscriberVerifiedMinutesAgo(6);

        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Queue::assertNotPushed(SendVerificationPromotionJob::class);
    }

    /** The boundary itself: exactly at the delay counts as elapsed. */
    public function test_subscriber_becomes_eligible_exactly_at_the_delay(): void
    {
        Queue::fake();
        $this->enableSection(delayMinutes: 5);
        $this->subscriberVerifiedMinutesAgo(5);

        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Queue::assertPushed(SendVerificationPromotionJob::class);
    }

    /** A one-minute delay is the setting most often used to test this by hand. */
    public function test_a_one_minute_delay_fires_on_the_next_sweep(): void
    {
        Queue::fake();
        $this->enableSection(delayMinutes: 1);
        $this->subscriberVerifiedMinutesAgo(1);

        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Queue::assertPushed(SendVerificationPromotionJob::class);
    }

    // ── Observability ────────────────────────────────────────────────────────

    /**
     * Both every-minute sweeps must carry an explicit overlap expiry. Without
     * one the mutex lives for 24 hours, so a killed run mutes the command for a
     * day while logging nothing at all — which is precisely how "cron is
     * clearly running but this never sends" happens.
     */
    public function test_every_minute_sweeps_have_a_bounded_overlap_mutex(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['promotions:dispatch-verification', 'promotions:dispatch-due'] as $signature) {
            $event = $events->first(
                static fn (Event $event): bool => str_contains((string) $event->command, $signature),
            );

            $this->assertInstanceOf(Event::class, $event, "{$signature} is not scheduled.");
            $this->assertTrue($event->withoutOverlapping, "{$signature} allows overlapping runs.");
            $this->assertLessThanOrEqual(
                60,
                $event->expiresAt,
                "{$signature} holds its overlap mutex for {$event->expiresAt} minutes; a stranded lock would mute it for that long.",
            );
        }
    }

    /** The clock context must be readable, and must never throw. */
    public function test_clock_facts_report_the_application_timezone(): void
    {
        config()->set('app.timezone', 'Asia/Yerevan');
        date_default_timezone_set('Asia/Yerevan');

        $facts = ClockFacts::forLog();

        $this->assertSame('Asia/Yerevan', $facts['timezone']);
        $this->assertSame(Carbon::now()->toDateTimeString(), $facts['now']);
        $this->assertArrayHasKey('drift_seconds', $facts);
        // SQLite has no UTC_TIMESTAMP(), so the database half is unavailable
        // here — the point is that it degrades instead of throwing.
        $this->assertArrayHasKey('db_now_utc', $facts);
    }

    protected function tearDown(): void
    {
        // The timezone is process-global, so a test that changed it must put it
        // back or it leaks into every test that runs afterwards.
        date_default_timezone_set('UTC');

        parent::tearDown();
    }
}
