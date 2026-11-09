<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPromotionBatchJob;
use App\Jobs\SendScheduleListBatchJob;
use App\Mail\PromotionEmail;
use App\Models\EmailSchedule;
use App\Models\MailgunReceiver;
use App\Models\Newsletter;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\Mail\WarmupRateLimiter;
use App\Services\PromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Schedule Setting sends at the warmup rate — WARMUP_EMAILS_PER_MINUTE a
 * minute per sending account, evenly spaced — and a batch that runs out of job
 * time re-queues the rest instead of being killed.
 */
class ScheduleSendPacingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow('2026-11-08 03:00:00');
        Sleep::fake(syncWithCarbon: true);
        config([
            'warmup.rate_limit.max_emails'           => 8,
            'warmup.rate_limit.window_seconds'       => 60,
            'warmup.rate_limit.min_interval_seconds' => null,
            'mail.admin_mailer'                      => 'smtp',
            'warmup.mailer'                          => 'smtp',
        ]);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function schedule(int $siteId, string $audience): EmailSchedule
    {
        return EmailSchedule::create([
            'site_id' => $siteId, 'audience' => $audience, 'limit' => 100, 'cooldown_days' => 1,
            'frequency' => 'daily', 'time' => '03:00', 'active' => true,
        ]);
    }

    public function test_a_newsletter_batch_is_spaced_at_the_warmup_rate(): void
    {
        [$site] = $this->siteWithKey();
        foreach (['a', 'b', 'c'] as $name) {
            Newsletter::create(['site_id' => $site->id, 'email' => "{$name}@example.com"]);
        }
        $schedule = $this->schedule($site->id, EmailSchedule::AUDIENCE_NEWSLETTERS);

        (new SendPromotionBatchJob($site->id, ['a@example.com', 'b@example.com', 'c@example.com'], scheduleId: $schedule->id))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, 3);
        Sleep::assertSequence([Sleep::usleep(7_500_000), Sleep::usleep(7_500_000)]);
    }

    public function test_the_env_smtp_mailbox_shares_warmups_window(): void
    {
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.com']);

        // Warmup just sent through the same mailbox.
        app(WarmupRateLimiter::class)->acquire();

        (new SendPromotionBatchJob($site->id, ['a@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        // So the schedule's first message keeps warmup's spacing.
        Mail::assertSent(PromotionEmail::class, 1);
        Sleep::assertSequence([Sleep::usleep(7_500_000)]);
    }

    public function test_a_newsletter_batch_out_of_time_requeues_the_addresses_it_did_not_reach(): void
    {
        [$site] = $this->siteWithKey();
        $emails = [];
        foreach (range(1, 4) as $i) {
            Newsletter::create(['site_id' => $site->id, 'email' => "n{$i}@example.com"]);
            $emails[] = "n{$i}@example.com";
        }
        $schedule = $this->schedule($site->id, EmailSchedule::AUDIENCE_NEWSLETTERS);
        // One second of budget: one send at once, one after its 7.5 s gap, then out of time.
        config(['promotions.batch_timeout' => 81]);
        $job = new SendPromotionBatchJob($site->id, $emails, scheduleId: $schedule->id);
        Queue::fake();

        $job->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, 2);
        Queue::assertPushed(SendPromotionBatchJob::class, fn (SendPromotionBatchJob $next): bool => $next->emails === ['n3@example.com', 'n4@example.com']
            && $next->scheduleId === $schedule->id);
        $this->assertDatabaseCount('schedule_send_histories', 2);
    }

    public function test_a_list_batch_out_of_time_requeues_the_rest_without_claiming_them(): void
    {
        [$site] = $this->siteWithKey();
        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = MailgunReceiver::create(['email' => "r{$i}@example.com", 'source' => 'manual'])->id;
        }
        $schedule = $this->schedule($site->id, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS);
        config(['promotions.batch_timeout' => 81]);
        $job = new SendScheduleListBatchJob($schedule->id, $site->id, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS, $ids);
        Queue::fake();

        $job->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, 2);
        Queue::assertPushed(SendScheduleListBatchJob::class, fn (SendScheduleListBatchJob $next): bool => $next->recipientIds === [$ids[2]]);
        // The re-queued receiver holds no claim yet, so it can still be mailed today.
        $this->assertDatabaseMissing('receiver_daily_claims', ['mailgun_receiver_id' => $ids[2]]);
    }
}
