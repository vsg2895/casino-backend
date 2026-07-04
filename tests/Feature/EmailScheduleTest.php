<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPromotionToSubscriberJob;
use App\Jobs\SendScheduledPromotionJob;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class EmailScheduleTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function schedule(Site $site, array $attrs = []): EmailSchedule
    {
        return EmailSchedule::create([
            'site_id'     => $site->id,
            'date_filter' => EmailSchedule::FILTER_TODAY,
            'frequency'   => EmailSchedule::FREQ_DAILY,
            'time'        => '03:00',
            'active'      => true,
            ...$attrs,
        ]);
    }

    // ── dateRange() presets (whole-day windows) ───────────────────────────

    public function test_date_range_covers_whole_days(): void
    {
        [$site] = $this->siteWithKey();
        $now = Carbon::create(2026, 5, 15, 3, 0); // Fri 2026-05-15

        $today = $this->schedule($site, ['date_filter' => 'today'])->dateRange($now);
        $this->assertSame('2026-05-15 00:00:00', $today[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-15 23:59:59', $today[1]->format('Y-m-d H:i:s'));

        $yest = $this->schedule($site, ['date_filter' => 'yesterday'])->dateRange($now);
        $this->assertSame('2026-05-14 00:00:00', $yest[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-14 23:59:59', $yest[1]->format('Y-m-d H:i:s'));

        $lastMonth = $this->schedule($site, ['date_filter' => 'last_month'])->dateRange($now);
        $this->assertSame('2026-04-01 00:00:00', $lastMonth[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-30 23:59:59', $lastMonth[1]->format('Y-m-d H:i:s'));

        $lastYear = $this->schedule($site, ['date_filter' => 'last_year'])->dateRange($now);
        $this->assertSame('2025-01-01 00:00:00', $lastYear[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2025-12-31 23:59:59', $lastYear[1]->format('Y-m-d H:i:s'));

        $specific = $this->schedule($site, ['date_filter' => 'specific', 'specific_date' => '2026-03-09'])->dateRange($now);
        $this->assertSame('2026-03-09 00:00:00', $specific[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 23:59:59', $specific[1]->format('Y-m-d H:i:s'));
    }

    // ── isDue() cadence logic ─────────────────────────────────────────────

    public function test_daily_is_due_only_at_its_time(): void
    {
        [$site] = $this->siteWithKey();
        $s = $this->schedule($site, ['frequency' => 'daily', 'time' => '03:00']);

        $this->assertTrue($s->isDue(Carbon::create(2026, 5, 15, 3, 0)));
        $this->assertFalse($s->isDue(Carbon::create(2026, 5, 15, 3, 1)));
        $this->assertFalse($s->isDue(Carbon::create(2026, 5, 15, 4, 0)));
    }

    public function test_weekly_is_due_on_its_weekday_and_time(): void
    {
        [$site] = $this->siteWithKey();
        // day_of_week 1 = Monday
        $s = $this->schedule($site, ['frequency' => 'weekly', 'time' => '09:30', 'day_of_week' => 1]);

        $this->assertTrue($s->isDue(Carbon::create(2026, 5, 18, 9, 30)));  // Monday
        $this->assertFalse($s->isDue(Carbon::create(2026, 5, 19, 9, 30))); // Tuesday
    }

    public function test_monthly_is_due_and_clamps_to_last_day(): void
    {
        [$site] = $this->siteWithKey();
        $s = $this->schedule($site, ['frequency' => 'monthly', 'time' => '00:00', 'day_of_month' => 31]);

        $this->assertTrue($s->isDue(Carbon::create(2026, 1, 31, 0, 0)));   // Jan has 31
        $this->assertTrue($s->isDue(Carbon::create(2026, 2, 28, 0, 0)));   // Feb clamps to 28
        $this->assertFalse($s->isDue(Carbon::create(2026, 2, 27, 0, 0)));
    }

    public function test_paused_schedule_is_never_due(): void
    {
        [$site] = $this->siteWithKey();
        $s = $this->schedule($site, ['active' => false, 'time' => '03:00']);
        $this->assertFalse($s->isDue(Carbon::create(2026, 5, 15, 3, 0)));
    }

    // ── Command dispatch + idempotency ────────────────────────────────────

    public function test_command_dispatches_due_schedules_once(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();
        Carbon::setTestNow(Carbon::create(2026, 5, 15, 3, 0));

        $due = $this->schedule($site, ['time' => '03:00']);
        $this->schedule($site, ['time' => '04:00']); // not due

        $this->artisan('promotions:dispatch-due')->assertSuccessful();
        Queue::assertPushed(SendScheduledPromotionJob::class, 1);

        // Running again in the same minute must not re-dispatch.
        $this->artisan('promotions:dispatch-due')->assertSuccessful();
        Queue::assertPushed(SendScheduledPromotionJob::class, 1);

        $this->assertNotNull($due->fresh()->last_run_at);
        Carbon::setTestNow();
    }

    // ── Campaign fan-out targets the right recipients ─────────────────────

    public function test_campaign_targets_only_the_window_and_skips_opt_outs(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();

        $inWindow = Newsletter::create(['site_id' => $site->id, 'email' => 'in@example.com']);
        $inWindow->forceFill(['created_at' => Carbon::today()->setTime(10, 0)])->save();

        $optedOut = Newsletter::create(['site_id' => $site->id, 'email' => 'out@example.com']);
        $optedOut->forceFill(['created_at' => Carbon::today()->setTime(11, 0)])->save();
        Unsubscribe::record($site->id, 'out@example.com', Unsubscribe::TYPE_PROMOTION);

        $oldContact = Newsletter::create(['site_id' => $site->id, 'email' => 'old@example.com']);
        $oldContact->forceFill(['created_at' => Carbon::today()->subDays(10)])->save();

        $schedule = $this->schedule($site, ['date_filter' => 'today']);

        (new SendScheduledPromotionJob($schedule->id))->handle();

        // Only the in-window, non-opted-out subscriber is queued.
        Queue::assertPushed(SendPromotionToSubscriberJob::class, 1);
        Queue::assertPushed(
            SendPromotionToSubscriberJob::class,
            fn (SendPromotionToSubscriberJob $job): bool => $job->email === 'in@example.com',
        );
    }

    public function test_campaign_does_nothing_when_promotion_template_is_off(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();
        $site->promotionEmailOrDefault()->update(['active' => false]);
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'x@example.com']);
        $n->forceFill(['created_at' => Carbon::today()->setTime(9, 0)])->save();

        (new SendScheduledPromotionJob($this->schedule($site)->id))->handle();

        Queue::assertNothingPushed();
    }

    // ── Per-recipient send routing ────────────────────────────────────────

    public function test_recipient_job_sends_via_newsletter_mailer(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'fan@example.com']);

        (new SendPromotionToSubscriberJob($site->id, 'fan@example.com'))
            ->handle(app(\App\Services\PromotionEmailService::class));

        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\PromotionEmail::class,
            fn ($mail): bool => $mail->hasTo('fan@example.com') && $mail->mailer === config('mail.newsletter_mailer'),
        );
    }

    // ── Admin CRUD ────────────────────────────────────────────────────────

    public function test_admin_can_create_a_schedule(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id' => $site->id,
            'name' => 'Daily blast',
            'date_filter' => 'yesterday',
            'frequency' => 'daily',
            'time' => '03:00',
            'active' => true,
        ])->assertCreated()->assertJsonPath('data.frequency', 'daily');

        $this->assertDatabaseHas('email_schedules', ['site_id' => $site->id, 'name' => 'Daily blast']);
    }

    public function test_weekly_requires_a_day_of_week(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id' => $site->id,
            'date_filter' => 'today',
            'frequency' => 'weekly',
            'time' => '03:00',
        ])->assertStatus(422)->assertJsonValidationErrorFor('day_of_week');
    }

    public function test_specific_filter_requires_a_date(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id' => $site->id,
            'date_filter' => 'specific',
            'frequency' => 'daily',
            'time' => '03:00',
        ])->assertStatus(422)->assertJsonValidationErrorFor('specific_date');
    }

    public function test_run_now_queues_the_campaign(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $schedule = $this->schedule($site);

        $this->postJson("/api/v1/admin/schedules/{$schedule->id}/run")
            ->assertOk()->assertJson(['ok' => true]);

        Queue::assertPushed(SendScheduledPromotionJob::class, 1);
        $this->assertNotNull($schedule->fresh()->last_run_at);
    }

    public function test_schedule_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/admin/schedules')->assertUnauthorized();
    }

    // ── Limit audience (no date filter → newest N by created_at desc) ──────

    public function test_campaign_uses_limit_to_target_the_newest_subscribers(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();

        $make = function (string $email, \Illuminate\Support\Carbon $when) use ($site): void {
            $n = Newsletter::create(['site_id' => $site->id, 'email' => $email]);
            $n->forceFill(['created_at' => $when])->save();
        };
        $make('newest@example.com', now());
        $make('middle@example.com', now()->subDay());
        $make('oldest@example.com', now()->subDays(2));

        $schedule = EmailSchedule::create([
            'site_id' => $site->id,
            'date_filter' => null,
            'limit' => 2,
            'frequency' => 'daily',
            'time' => '03:00',
            'active' => true,
        ]);

        (new SendScheduledPromotionJob($schedule->id))->handle();

        // Only the two most-recent sign-ups, oldest excluded.
        Queue::assertPushed(SendPromotionToSubscriberJob::class, 2);
        Queue::assertPushed(SendPromotionToSubscriberJob::class, fn ($j): bool => $j->email === 'newest@example.com');
        Queue::assertPushed(SendPromotionToSubscriberJob::class, fn ($j): bool => $j->email === 'middle@example.com');
        Queue::assertNotPushed(SendPromotionToSubscriberJob::class, fn ($j): bool => $j->email === 'oldest@example.com');
    }

    public function test_limit_case_still_excludes_promotion_opt_outs(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'ok@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'out@example.com']);
        Unsubscribe::record($site->id, 'out@example.com', Unsubscribe::TYPE_PROMOTION);

        $schedule = EmailSchedule::create([
            'site_id' => $site->id, 'date_filter' => null, 'limit' => 10,
            'frequency' => 'daily', 'time' => '03:00', 'active' => true,
        ]);

        (new SendScheduledPromotionJob($schedule->id))->handle();

        Queue::assertPushed(SendPromotionToSubscriberJob::class, 1);
        Queue::assertPushed(SendPromotionToSubscriberJob::class, fn ($j): bool => $j->email === 'ok@example.com');
    }

    public function test_admin_can_create_a_limit_schedule(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id' => $site->id,
            'date_filter' => null,
            'limit' => 250,
            'frequency' => 'daily',
            'time' => '03:00',
        ])->assertCreated()
            ->assertJsonPath('data.limit', 250)
            ->assertJsonPath('data.date_filter', null);
    }

    public function test_limit_is_required_when_no_date_filter(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id' => $site->id,
            'frequency' => 'daily',
            'time' => '03:00',
        ])->assertStatus(422)->assertJsonValidationErrorFor('limit');
    }

    public function test_limit_is_dropped_when_a_date_filter_is_set(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // Supplying both: the date filter wins, the limit is nulled.
        $this->postJson('/api/v1/admin/schedules', [
            'site_id' => $site->id,
            'date_filter' => 'today',
            'limit' => 99,
            'frequency' => 'daily',
            'time' => '03:00',
        ])->assertCreated()
            ->assertJsonPath('data.date_filter', 'today')
            ->assertJsonPath('data.limit', null);
    }
}
