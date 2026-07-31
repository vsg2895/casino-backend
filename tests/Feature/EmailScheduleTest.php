<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPromotionBatchJob;
use App\Jobs\SendScheduledPromotionJob;
use App\Mail\PromotionEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\ScheduleRecipientService;
use App\Services\PromotionEmailService;
use Illuminate\Support\Facades\Mail;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\SendgridKey;
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

        (new SendScheduledPromotionJob($schedule->id))->handle(app(ScheduleRecipientService::class));

        // One batch job holding only the in-window, non-opted-out subscriber.
        Queue::assertPushed(SendPromotionBatchJob::class, 1);
        Queue::assertPushed(
            SendPromotionBatchJob::class,
            fn (SendPromotionBatchJob $job): bool => $job->emails === ['in@example.com'],
        );
    }

    public function test_campaign_does_nothing_when_promotion_template_is_off(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();
        $site->promotionEmailOrDefault()->update(['active' => false]);
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'x@example.com']);
        $n->forceFill(['created_at' => Carbon::today()->setTime(9, 0)])->save();

        (new SendScheduledPromotionJob($this->schedule($site)->id))->handle(app(ScheduleRecipientService::class));

        Queue::assertNothingPushed();
    }

    // ── Per-recipient send routing ────────────────────────────────────────

    public function test_batch_job_sends_each_recipient_via_admin_smtp_mailer(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'b@example.com']);

        (new SendPromotionBatchJob($site->id, ['a@example.com', 'b@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, 2);
        Mail::assertSent(
            PromotionEmail::class,
            // Promotion campaigns are admin-operated mail → admin SMTP mailer.
            fn ($mail): bool => $mail->hasTo('a@example.com') && $mail->mailer === config('mail.admin_mailer'),
        );
    }

    public function test_batch_job_skips_recipients_who_opted_out_after_fan_out(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'stay@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'left@example.com']);
        // Opted out between fan-out and batch send.
        Unsubscribe::record($site->id, 'left@example.com', Unsubscribe::TYPE_PROMOTION);

        (new SendPromotionBatchJob($site->id, ['stay@example.com', 'left@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, 1);
        Mail::assertSent(PromotionEmail::class, fn ($mail): bool => $mail->hasTo('stay@example.com'));
    }

    // ── Failed-case handling: once per email per day, retry-safe ───────────

    public function test_same_template_is_not_sent_to_an_email_twice_in_a_day(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'once@example.com']);
        $service = app(PromotionEmailService::class);

        (new SendPromotionBatchJob($site->id, ['once@example.com']))->handle($service, app(PromotionMailerFactory::class));
        // Same batch runs again the same day (e.g. duplicate schedule / re-run).
        (new SendPromotionBatchJob($site->id, ['once@example.com']))->handle($service, app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, 1); // delivered exactly once
        // Exactly one success record; the second run is recorded as skipped.
        $this->assertSame(1, PromotionEmailHistory::query()->where('status', 'success')->count());
        $this->assertDatabaseHas('promotion_email_histories', [
            'site_id'   => $site->id,
            'email'     => 'once@example.com',
            'sent_date' => now()->toDateString(),
            'status'    => 'success',
        ]);
        $this->assertDatabaseHas('promotion_email_histories', [
            'email'  => 'once@example.com',
            'status' => 'skipped',
        ]);
    }

    public function test_retry_after_partial_delivery_skips_already_sent(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'delivered@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'pending@example.com']);
        // Simulate: 'delivered' went out before a mid-batch failure; the retry
        // re-runs the same batch.
        PromotionEmailHistory::recordMany($site->id, ['delivered@example.com']);

        (new SendPromotionBatchJob($site->id, ['delivered@example.com', 'pending@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, 1);
        Mail::assertSent(PromotionEmail::class, fn ($m): bool => $m->hasTo('pending@example.com'));
        Mail::assertNotSent(PromotionEmail::class, fn ($m): bool => $m->hasTo('delivered@example.com'));
    }

    public function test_a_single_failing_recipient_does_not_abort_the_batch(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        // 'bad' is created first so it is processed before 'good'.
        Newsletter::create(['site_id' => $site->id, 'email' => 'bad@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'good@example.com']);

        $real = app(PromotionEmailService::class);
        $service = \Mockery::mock(PromotionEmailService::class);
        $service->shouldReceive('mailFor')->andReturnUsing(
            function ($site, $template, string $email, string $token) use ($real) {
                if ($email === 'bad@example.com') {
                    throw new \RuntimeException('boom');
                }

                return $real->mailFor($site, $template, $email, $token);
            },
        );

        (new SendPromotionBatchJob($site->id, ['bad@example.com', 'good@example.com']))->handle($service, app(PromotionMailerFactory::class));

        // The good recipient still went out; each attempt keeps its outcome.
        Mail::assertSent(PromotionEmail::class, 1);
        Mail::assertSent(PromotionEmail::class, fn ($m): bool => $m->hasTo('good@example.com'));
        $this->assertDatabaseHas('promotion_email_histories', ['site_id' => $site->id, 'email' => 'good@example.com', 'status' => 'success']);
        $this->assertDatabaseHas('promotion_email_histories', ['site_id' => $site->id, 'email' => 'bad@example.com', 'status' => 'failed']);
        $this->assertDatabaseMissing('promotion_email_histories', ['site_id' => $site->id, 'email' => 'bad@example.com', 'status' => 'success']);
    }

    public function test_batch_job_retries_once_on_failure(): void
    {
        $this->assertSame(2, (new SendPromotionBatchJob(1, ['a@example.com']))->tries);
    }

    // ── Recipient preview / count / export ────────────────────────────────

    /** Create $count subscribers for $site, signed up at $when. */
    private function subscribers(Site $site, int $count, ?Carbon $when = null, string $prefix = 'sub'): void
    {
        $when ??= Carbon::today()->setTime(9, 0);

        for ($i = 0; $i < $count; $i++) {
            $n = Newsletter::create(['site_id' => $site->id, 'email' => "{$prefix}{$i}@example.com"]);
            $n->forceFill(['created_at' => $when])->save();
        }
    }

    public function test_preview_count_matches_what_the_send_would_dispatch(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->subscribers($site, 7);
        $this->subscribers($site, 3, Carbon::today()->subDays(5), 'old');   // outside the window
        Unsubscribe::record($site->id, 'sub0@example.com', Unsubscribe::TYPE_PROMOTION); // opted out

        $schedule = $this->schedule($site, ['date_filter' => 'today']);

        // What the admin previews…
        $previewed = $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()
            ->json('data.count');

        // …must equal what the campaign actually fans out.
        (new SendScheduledPromotionJob($schedule->id))->handle(app(ScheduleRecipientService::class));
        $dispatched = collect(Queue::pushed(SendPromotionBatchJob::class))
            ->sum(fn (SendPromotionBatchJob $j): int => count($j->emails));

        $this->assertSame(6, $previewed); // 7 in window − 1 opt-out
        $this->assertSame($previewed, $dispatched);
    }

    public function test_preview_count_respects_the_newest_n_limit(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 10);

        $schedule = EmailSchedule::create([
            'site_id' => $site->id, 'date_filter' => null, 'limit' => 4,
            'frequency' => 'daily', 'time' => '03:00', 'active' => true,
        ]);

        // The cap must be applied — not the full audience size.
        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()
            ->assertJsonPath('data.count', 4)
            ->assertJsonCount(4, 'data.sample');
    }

    public function test_preview_returns_a_bounded_live_sample(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 30);
        $schedule = $this->schedule($site);

        $response = $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients?sample=5")
            ->assertOk()
            ->assertJsonPath('data.count', 30)
            ->assertJsonCount(5, 'data.sample')
            ->assertJsonStructure(['data' => ['count', 'sample' => [['email', 'created_at']], 'generated_at']]);

        // Live data: a new sign-up is reflected on the next call, uncached.
        $this->subscribers($site, 1, null, 'fresh');
        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()->assertJsonPath('data.count', 31);

        $this->assertNotEmpty($response->json('data.sample.0.email'));
    }

    public function test_export_contains_exactly_the_recipients_and_only_two_columns(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 4);
        $this->subscribers($site, 2, Carbon::today()->subDays(9), 'old');
        Unsubscribe::record($site->id, 'sub1@example.com', Unsubscribe::TYPE_PROMOTION);

        $schedule = $this->schedule($site, ['date_filter' => 'today']);

        $csv = $this->get("/api/v1/admin/schedules/{$schedule->id}/recipients/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $rows = array_values(array_filter(explode("\n", trim($csv))));
        $header = str_getcsv(ltrim($rows[0], "\xEF\xBB\xBF"));

        // Only the two requested columns.
        $this->assertSame(['email', 'created_at'], $header);
        // One line per recipient — opt-out and out-of-window rows absent.
        $this->assertCount(3 + 1, $rows);
        $this->assertStringContainsString('sub0@example.com', $csv);
        $this->assertStringNotContainsString('sub1@example.com', $csv); // opted out
        $this->assertStringNotContainsString('old0@example.com', $csv); // outside window
    }

    public function test_export_row_count_equals_the_preview_count(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 12);
        $schedule = EmailSchedule::create([
            'site_id' => $site->id, 'date_filter' => null, 'limit' => 5,
            'frequency' => 'daily', 'time' => '03:00', 'active' => true,
        ]);

        $count = $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")->json('data.count');
        $csv = $this->get("/api/v1/admin/schedules/{$schedule->id}/recipients/export")->streamedContent();
        $dataRows = count(array_filter(explode("\n", trim($csv)))) - 1; // minus header

        $this->assertSame(5, $count);
        $this->assertSame($count, $dataRows);
    }

    // ── 24h dedup is reflected in the count ───────────────────────────────

    public function test_count_excludes_subscribers_already_mailed_within_24h(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 10);
        $schedule = $this->schedule($site, ['date_filter' => 'today']);

        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()->assertJsonPath('data.count', 10);

        // Four of them already received today's promotion.
        PromotionEmailHistory::recordMany($site->id, [
            'sub0@example.com', 'sub1@example.com', 'sub2@example.com', 'sub3@example.com',
        ]);

        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()
            ->assertJsonPath('data.count', 6)
            ->assertJsonMissing(['email' => 'sub0@example.com']);
    }

    public function test_a_second_run_resolves_to_zero_recipients(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 5);
        $schedule = $this->schedule($site, ['date_filter' => 'today']);
        $service = app(ScheduleRecipientService::class);

        // First run delivers to all five.
        PromotionEmailHistory::recordMany($site->id, array_map(
            fn (int $i): string => "sub{$i}@example.com",
            range(0, 4),
        ));

        // Second run: nothing left to send, and the preview says so.
        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()->assertJsonPath('data.count', 0);

        (new SendScheduledPromotionJob($schedule->id))->handle($service);
        Queue::assertNothingPushed();
    }

    public function test_newest_n_does_not_reach_deeper_after_a_send(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        // 10 subscribers, schedule targets the newest 4.
        $this->subscribers($site, 10);
        $schedule = EmailSchedule::create([
            'site_id' => $site->id, 'date_filter' => null, 'limit' => 4,
            'frequency' => 'daily', 'time' => '03:00', 'active' => true,
        ]);

        $newest = app(ScheduleRecipientService::class)->sample($schedule, 4)->pluck('email')->all();
        $this->assertCount(4, $newest);

        // Those four have now been mailed.
        PromotionEmailHistory::recordMany($site->id, $newest);

        // The cap must NOT slide down to the next four — the audience is still
        // "the newest 4", and all of them are done.
        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()->assertJsonPath('data.count', 0);
    }

    public function test_failed_and_skipped_attempts_do_not_reduce_the_count(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 3);
        $schedule = $this->schedule($site, ['date_filter' => 'today']);

        // Only successful deliveries suppress a re-send.
        PromotionEmailHistory::recordAttempts($site->id, [
            'sub0@example.com' => PromotionEmailHistory::STATUS_FAILED,
            'sub1@example.com' => PromotionEmailHistory::STATUS_SKIPPED,
        ]);

        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()->assertJsonPath('data.count', 3);
    }

    public function test_a_delivery_older_than_24h_counts_again(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 2);
        $schedule = $this->schedule($site, ['date_filter' => 'today']);

        // Delivered 25 hours ago — outside the window, so eligible again.
        $old = now()->subHours(25);
        PromotionEmailHistory::insert([
            'site_id'    => $site->id,
            'email'      => 'sub0@example.com',
            'sent_date'  => $old->toDateString(),
            'status'     => PromotionEmailHistory::STATUS_SUCCESS,
            'created_at' => $old,
        ]);

        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")
            ->assertOk()->assertJsonPath('data.count', 2);
    }

    public function test_export_and_send_also_honour_the_24h_window(): void
    {
        Queue::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->subscribers($site, 6);
        $schedule = $this->schedule($site, ['date_filter' => 'today']);
        PromotionEmailHistory::recordMany($site->id, ['sub0@example.com', 'sub1@example.com']);

        $count = $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")->json('data.count');

        $csv = $this->get("/api/v1/admin/schedules/{$schedule->id}/recipients/export")->streamedContent();
        $dataRows = count(array_filter(explode("\n", trim($csv)))) - 1;

        (new SendScheduledPromotionJob($schedule->id))->handle(app(ScheduleRecipientService::class));
        $fanned = collect(Queue::pushed(SendPromotionBatchJob::class))
            ->sum(fn (SendPromotionBatchJob $j): int => count($j->emails));

        $this->assertSame(4, $count);
        $this->assertSame($count, $dataRows);
        $this->assertSame($count, $fanned);
        $this->assertStringNotContainsString('sub0@example.com', $csv);
    }

    public function test_recipient_endpoints_require_auth(): void
    {
        [$site] = $this->siteWithKey();
        $schedule = $this->schedule($site);

        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients")->assertUnauthorized();
        $this->getJson("/api/v1/admin/schedules/{$schedule->id}/recipients/export")->assertUnauthorized();
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

        (new SendScheduledPromotionJob($schedule->id))->handle(app(ScheduleRecipientService::class));

        // One batch job with the two most-recent sign-ups, oldest excluded.
        Queue::assertPushed(SendPromotionBatchJob::class, 1);
        Queue::assertPushed(SendPromotionBatchJob::class, function (SendPromotionBatchJob $job): bool {
            return count($job->emails) === 2
                && in_array('newest@example.com', $job->emails, true)
                && in_array('middle@example.com', $job->emails, true)
                && ! in_array('oldest@example.com', $job->emails, true);
        });
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

        (new SendScheduledPromotionJob($schedule->id))->handle(app(ScheduleRecipientService::class));

        Queue::assertPushed(SendPromotionBatchJob::class, 1);
        Queue::assertPushed(SendPromotionBatchJob::class, fn (SendPromotionBatchJob $j): bool => $j->emails === ['ok@example.com']);
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

    // ── Email provider (SMTP via .env / SendGrid via stored key) ──────────

    private function sendgridKey(array $attrs = []): SendgridKey
    {
        return SendgridKey::create([
            'name'    => 'Campaign key',
            'api_key' => 'SG.test-key-abcdefghijklmnop.qrstuvwxyz123456',
            'status'  => SendgridKey::STATUS_ACTIVE,
            ...$attrs,
        ]);
    }

    public function test_provider_defaults_to_smtp_for_backward_compatibility(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // A client that predates the provider field sends nothing — the
        // schedule must behave exactly as before (SMTP).
        $this->postJson('/api/v1/admin/schedules', [
            'site_id'     => $site->id,
            'date_filter' => 'today',
            'frequency'   => 'daily',
            'time'        => '03:00',
        ])->assertCreated()
            ->assertJsonPath('data.provider', 'smtp')
            ->assertJsonPath('data.sendgrid_key_id', null);
    }

    public function test_sendgrid_provider_requires_a_key(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id'     => $site->id,
            'date_filter' => 'today',
            'frequency'   => 'daily',
            'time'        => '03:00',
            'provider'    => 'sendgrid',
        ])->assertStatus(422)->assertJsonValidationErrorFor('sendgrid_key_id');
    }

    public function test_sendgrid_provider_rejects_an_inactive_key(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->sendgridKey(['status' => SendgridKey::STATUS_INACTIVE]);

        $this->postJson('/api/v1/admin/schedules', [
            'site_id'         => $site->id,
            'date_filter'     => 'today',
            'frequency'       => 'daily',
            'time'            => '03:00',
            'provider'        => 'sendgrid',
            'sendgrid_key_id' => $key->id,
        ])->assertStatus(422)->assertJsonValidationErrorFor('sendgrid_key_id');
    }

    public function test_admin_can_create_a_sendgrid_schedule(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->sendgridKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id'         => $site->id,
            'date_filter'     => 'today',
            'frequency'       => 'daily',
            'time'            => '03:00',
            'provider'        => 'sendgrid',
            'sendgrid_key_id' => $key->id,
        ])->assertCreated()
            ->assertJsonPath('data.provider', 'sendgrid')
            ->assertJsonPath('data.sendgrid_key_id', $key->id);
    }

    public function test_stale_sendgrid_key_is_dropped_when_provider_is_smtp(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $key = $this->sendgridKey();

        // Switching back to SMTP with a leftover key id must not persist it.
        $this->postJson('/api/v1/admin/schedules', [
            'site_id'         => $site->id,
            'date_filter'     => 'today',
            'frequency'       => 'daily',
            'time'            => '03:00',
            'provider'        => 'smtp',
            'sendgrid_key_id' => $key->id,
        ])->assertCreated()
            ->assertJsonPath('data.provider', 'smtp')
            ->assertJsonPath('data.sendgrid_key_id', null);
    }

    // ── Delivery transport routing ─────────────────────────────────────────

    public function test_fan_out_propagates_the_schedule_provider_to_batches(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();
        $key = $this->sendgridKey();
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'sub@example.com']);
        $n->forceFill(['created_at' => Carbon::today()->setTime(9, 0)])->save();

        $schedule = $this->schedule($site, [
            'provider'        => EmailSchedule::PROVIDER_SENDGRID,
            'sendgrid_key_id' => $key->id,
        ]);

        (new SendScheduledPromotionJob($schedule->id))->handle(app(ScheduleRecipientService::class));

        Queue::assertPushed(
            SendPromotionBatchJob::class,
            fn (SendPromotionBatchJob $job): bool => $job->provider === 'sendgrid'
                && $job->sendgridKeyId === $key->id,
        );
    }

    public function test_batch_job_sends_via_the_stored_sendgrid_key(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $key = $this->sendgridKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'sg@example.com']);

        (new SendPromotionBatchJob($site->id, ['sg@example.com'], EmailSchedule::PROVIDER_SENDGRID, $key->id))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(
            PromotionEmail::class,
            // Routed through the per-key runtime mailer, not the .env SMTP one.
            fn ($mail): bool => $mail->hasTo('sg@example.com') && $mail->mailer === "sendgrid_key_{$key->id}",
        );
    }

    public function test_batch_job_skips_gracefully_when_the_key_is_inactive(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $key = $this->sendgridKey(['status' => SendgridKey::STATUS_INACTIVE]);
        Newsletter::create(['site_id' => $site->id, 'email' => 'sg@example.com']);

        // Key was disabled after the schedule was saved: the batch must log and
        // stop — no exception, no delivery, nothing marked as sent.
        (new SendPromotionBatchJob($site->id, ['sg@example.com'], EmailSchedule::PROVIDER_SENDGRID, $key->id))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertNothingSent();
        $this->assertDatabaseCount('promotion_email_histories', 0);
    }

    public function test_batch_job_skips_gracefully_when_the_key_was_deleted(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'sg@example.com']);

        // FK nullOnDelete leaves the schedule with no key id.
        (new SendPromotionBatchJob($site->id, ['sg@example.com'], EmailSchedule::PROVIDER_SENDGRID, null))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertNothingSent();
        $this->assertDatabaseCount('promotion_email_histories', 0);
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
