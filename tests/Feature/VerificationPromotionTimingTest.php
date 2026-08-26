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
use Illuminate\Support\Facades\Log;
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

    private function scheduledEvent(string $signature): Event
    {
        $event = collect(app(Schedule::class)->events())->first(
            static fn (Event $event): bool => str_contains((string) $event->command, $signature),
        );

        $this->assertInstanceOf(Event::class, $event, "{$signature} is not scheduled.");

        return $event;
    }

    /**
     * The post-verification sweep must take NO overlap mutex.
     *
     * Overlapping runs are already harmless — the job claims each subscriber
     * with a conditional UPDATE, so a double dispatch cannot become a double
     * send. The mutex therefore protected nothing, while a stranded one muted
     * the command entirely and silently. Its TTL is fixed when the lock is
     * taken, so a shorter expiry cannot rescue a lock that is already stuck;
     * only not taking one makes the sweep self-healing.
     */
    public function test_the_verification_sweep_takes_no_overlap_mutex(): void
    {
        $event = $this->scheduledEvent('promotions:dispatch-verification');

        $this->assertFalse(
            $event->withoutOverlapping,
            'The post-verification sweep must not take an overlap mutex: a stranded lock silently mutes it, '
            .'and the atomic claim in SendVerificationPromotionJob already prevents double sends.',
        );
    }

    /**
     * The campaign sweep keeps its mutex — a fan-out of tens of thousands of
     * emails is genuinely worth serialising — but the expiry must stay bounded.
     * Laravel's default with no argument is 24 hours, which turns one killed run
     * into a day-long silent outage.
     */
    public function test_the_campaign_sweep_has_a_bounded_overlap_mutex(): void
    {
        $event = $this->scheduledEvent('promotions:dispatch-due');

        $this->assertTrue($event->withoutOverlapping);
        $this->assertLessThanOrEqual(
            60,
            $event->expiresAt,
            "promotions:dispatch-due holds its overlap mutex for {$event->expiresAt} minutes; "
            .'a stranded lock would mute it for that long.',
        );
    }

    // ── Audience reporting ───────────────────────────────────────────────────

    /**
     * When nobody is eligible, the log must say how many subscribers reached
     * each stage — otherwise "0 eligible" is a fact with no explanation.
     */
    public function test_the_sweep_logs_the_audience_funnel_when_nobody_is_eligible(): void
    {
        Queue::fake();
        Log::spy();

        $this->enableSection(delayMinutes: 30);
        $this->subscriberVerifiedMinutesAgo(1);   // verified, but far too recently

        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context = []): bool {
                if ($message !== 'Post-verification promotion sweep found no eligible subscribers') {
                    return false;
                }

                return ($context['audience']['subscribers'] ?? null) === 1
                    && ($context['audience']['verified'] ?? null) === 1
                    && ($context['audience']['with_verified_at'] ?? null) === 1
                    // The delay has NOT elapsed — this is the row that explains it.
                    && ($context['audience']['delay_elapsed'] ?? null) === 0
                    && ($context['audience']['eligible_now'] ?? null) === 0;
            })
            ->once();
    }

    /** When subscribers ARE queued, the log must report how many. */
    public function test_the_sweep_logs_how_many_subscribers_were_queued(): void
    {
        Queue::fake();
        Log::spy();

        $this->enableSection(delayMinutes: 5);
        $this->subscriberVerifiedMinutesAgo(10);

        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'Post-verification promotions queued'
                && ($context['count'] ?? null) === 1
                && ($context['limit_reached'] ?? null) === false)
            ->once();
    }

    /** The heartbeat fires before any check, so silence means "never ran". */
    public function test_the_sweep_logs_a_heartbeat_even_while_the_feature_is_disabled(): void
    {
        Queue::fake();
        Log::spy();

        VerificationPromotionEmail::current()->update(['active' => false]);

        $this->artisan('promotions:dispatch-verification')->assertSuccessful();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []): bool => $message === 'Post-verification promotion sweep running'
                && array_key_exists('drift_seconds', $context))
            ->once();
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
