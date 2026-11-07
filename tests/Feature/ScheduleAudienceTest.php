<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPromotionBatchJob;
use App\Jobs\SendScheduledPromotionJob;
use App\Jobs\SendScheduleListBatchJob;
use App\Mail\PromotionEmail;
use App\Models\EmailSchedule;
use App\Models\MailgunReceiver;
use App\Models\MailgunSuppression;
use App\Models\Newsletter;
use App\Models\ScheduleSendHistory;
use App\Models\SendgridKey;
use App\Models\SendgridReceiver;
use App\Models\Site;
use App\Models\WarmupEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use App\Services\ScheduleRecipientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Schedules that mail a list other than the site's newsletters — Mailgun or
 * Sendgrid Receivers, or warmup — and the history every schedule now writes.
 */
class ScheduleAudienceTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function schedule(Site $site, string $audience, array $attrs = []): EmailSchedule
    {
        return EmailSchedule::create([
            'site_id'       => $site->id,
            'name'          => 'Receivers blast',
            'audience'      => $audience,
            'limit'         => 100,
            'cooldown_days' => 1,
            'frequency'     => EmailSchedule::FREQ_DAILY,
            'time'          => '03:00',
            'active'        => true,
            ...$attrs,
        ]);
    }

    private function receiver(string $model, string $email, int $minutesAgo = 0): MailgunReceiver|SendgridReceiver
    {
        $receiver = $model::create(['email' => $email, 'name' => 'Ann', 'source' => 'manual']);
        $receiver->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

        return $receiver;
    }

    private function runBatch(SendScheduleListBatchJob $job): void
    {
        $job->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));
    }

    public function test_a_receiver_audience_fans_out_ids_of_the_newest_sendable_addresses(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();
        $newest = $this->receiver(MailgunReceiver::class, 'new@example.com', 1);
        $unsubscribed = $this->receiver(MailgunReceiver::class, 'gone@example.com', 2);
        $unsubscribed->forceFill(['unsubscribed_at' => now()])->save();
        $this->receiver(MailgunReceiver::class, 'bounced@example.com', 3);
        MailgunSuppression::suppress('bounced@example.com', MailgunSuppression::REASON_BOUNCE);
        $older = $this->receiver(MailgunReceiver::class, 'old@example.com', 4);
        $this->receiver(MailgunReceiver::class, 'oldest@example.com', 5);
        // The other list is never touched by a Mailgun-audience schedule.
        $this->receiver(SendgridReceiver::class, 'sg@example.com');

        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS, ['limit' => 2]);

        $this->assertSame(2, app(ScheduleRecipientService::class)->count($schedule));

        (new SendScheduledPromotionJob($schedule->id))->handle(app(ScheduleRecipientService::class));

        Queue::assertNotPushed(SendPromotionBatchJob::class);
        Queue::assertPushed(SendScheduleListBatchJob::class, 1);
        Queue::assertPushed(
            SendScheduleListBatchJob::class,
            fn (SendScheduleListBatchJob $job): bool => $job->recipientIds === [$newest->id, $older->id]
                && $job->audience === EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS
                && $job->scheduleId === $schedule->id,
        );
    }

    public function test_a_list_batch_sends_the_promotion_with_the_lists_own_unsubscribe_and_records_it(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $key = SendgridKey::create(['name' => 'Main key', 'api_key' => 'SG.test-key-value-long-enough', 'status' => 'active']);
        $receiver = $this->receiver(SendgridReceiver::class, 'person@example.com');
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_SENDGRID_RECEIVERS, [
            'provider' => EmailSchedule::PROVIDER_SENDGRID, 'sendgrid_key_id' => $key->id,
        ]);

        $this->runBatch(new SendScheduleListBatchJob(
            $schedule->id, $site->id, EmailSchedule::AUDIENCE_SENDGRID_RECEIVERS, [$receiver->id],
            EmailSchedule::PROVIDER_SENDGRID, $key->id,
        ));

        Mail::assertSent(PromotionEmail::class, fn (PromotionEmail $mail): bool => $mail->hasTo('person@example.com')
            && $mail->oneClickUrl === $receiver->unsubscribeUrl()
            && str_contains($mail->unsubscribeUrl, '/api/v1/sendgrid-unsubscribe/'));

        $this->assertDatabaseHas('schedule_send_histories', [
            'email_schedule_id' => $schedule->id,
            'schedule_name'     => 'Receivers blast',
            'site_id'           => $site->id,
            'audience'          => 'sendgrid_receivers',
            'recipient_id'      => $receiver->id,
            'email'             => 'person@example.com',
            'template'          => 'promotion',
            'provider'          => 'sendgrid',
            'credential_id'     => $key->id,
            'credential_label'  => 'SendGrid · Main key',
            'status'            => 'sent',
        ]);
        // The list's one-email-per-day claim is taken, so its own campaigns skip them today.
        $this->assertDatabaseHas('sendgrid_receiver_daily_claims', ['sendgrid_receiver_id' => $receiver->id, 'channel' => 'schedule']);
    }

    public function test_a_receiver_the_list_already_mailed_today_is_skipped_not_sent(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $receiver = $this->receiver(MailgunReceiver::class, 'claimed@example.com');
        DB::table('receiver_daily_claims')->insert([
            'mailgun_receiver_id' => $receiver->id, 'claim_on' => now()->toDateString(),
            'channel' => 'mailgun', 'credential_id' => 1, 'created_at' => now(),
        ]);
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS);

        $this->assertSame(0, app(ScheduleRecipientService::class)->count($schedule));

        $this->runBatch(new SendScheduleListBatchJob($schedule->id, $site->id, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS, [$receiver->id]));

        Mail::assertNothingSent();
        $this->assertDatabaseHas('schedule_send_histories', ['email' => 'claimed@example.com', 'status' => 'skipped']);
    }

    public function test_the_cooldown_is_per_site_and_template_across_schedules(): void
    {
        [$site] = $this->siteWithKey();
        [$otherSite] = $this->siteWithKey();
        $receiver = $this->receiver(MailgunReceiver::class, 'rest@example.com');
        $first = $this->schedule($site, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS);
        $second = $this->schedule($site, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS, ['cooldown_days' => 2]);
        $elsewhere = $this->schedule($otherSite, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS, ['cooldown_days' => 2]);
        $service = app(ScheduleRecipientService::class);

        // The first schedule mailed them 30 hours ago.
        Carbon::setTestNow(now()->subHours(30));
        ScheduleSendHistory::record([[
            'email_schedule_id' => $first->id, 'schedule_name' => null, 'site_id' => $site->id,
            'audience' => 'mailgun_receivers', 'recipient_id' => $receiver->id, 'email' => 'rest@example.com',
            'template' => 'promotion', 'provider' => 'smtp', 'credential_id' => null,
            'credential_label' => 'SMTP (.env)', 'from_address' => null, 'status' => 'sent', 'error' => null,
        ]]);
        Carbon::setTestNow();

        $this->assertSame(1, $service->count($first));      // one-day rest is over
        $this->assertSame(0, $service->count($second));     // same site + template, two-day rest
        $this->assertSame(1, $service->count($elsewhere));  // another site's promotion is a different template
    }

    public function test_a_warmup_audience_mails_the_warmup_list(): void
    {
        Mail::fake();
        Queue::fake();
        [$site] = $this->siteWithKey();
        $seed = WarmupEmail::create(['email' => 'seed@example.com']);
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_WARMUP);

        (new SendScheduledPromotionJob($schedule->id))->handle(app(ScheduleRecipientService::class));
        Queue::assertPushed(SendScheduleListBatchJob::class, fn (SendScheduleListBatchJob $job): bool => $job->recipientIds === [$seed->id]);

        $this->runBatch(new SendScheduleListBatchJob($schedule->id, $site->id, EmailSchedule::AUDIENCE_WARMUP, [$seed->id]));

        Mail::assertSent(PromotionEmail::class, fn (PromotionEmail $mail): bool => $mail->hasTo('seed@example.com'));
        $this->assertDatabaseHas('schedule_send_histories', ['email' => 'seed@example.com', 'audience' => 'warmup', 'status' => 'sent']);
        // The warmup list's own state is the warmup feature's — untouched.
        $this->assertNull($seed->fresh()->last_sent_at);
    }

    public function test_a_newsletter_batch_from_a_schedule_writes_history_and_honours_the_cooldown(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'sub@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'resting@example.com']);
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_NEWSLETTERS, ['cooldown_days' => 3]);

        Carbon::setTestNow(now()->subDays(2));
        ScheduleSendHistory::record([[
            'email_schedule_id' => $schedule->id, 'schedule_name' => null, 'site_id' => $site->id,
            'audience' => 'newsletters', 'recipient_id' => null, 'email' => 'resting@example.com',
            'template' => 'promotion', 'provider' => 'smtp', 'credential_id' => null,
            'credential_label' => 'SMTP (.env)', 'from_address' => null, 'status' => 'sent', 'error' => null,
        ]]);
        Carbon::setTestNow();

        (new SendPromotionBatchJob($site->id, ['sub@example.com', 'resting@example.com'], scheduleId: $schedule->id))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, 1);
        Mail::assertSent(PromotionEmail::class, fn (PromotionEmail $mail): bool => $mail->hasTo('sub@example.com'));
        $this->assertDatabaseHas('schedule_send_histories', [
            'email' => 'sub@example.com', 'recipient_id' => $subscriber->id, 'audience' => 'newsletters',
            'credential_label' => 'SMTP (.env)', 'status' => 'sent',
        ]);
        $this->assertDatabaseHas('schedule_send_histories', ['email' => 'resting@example.com', 'status' => 'skipped']);
        // The shared 24h promotion log is still written for newsletters.
        $this->assertDatabaseHas('promotion_email_histories', ['email' => 'sub@example.com', 'status' => 'success']);
    }

    public function test_the_history_endpoint_lists_and_counts_with_filters(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS);
        $row = fn (string $email, string $audience, string $status): array => [
            'email_schedule_id' => $schedule->id, 'schedule_name' => 'Receivers blast', 'site_id' => $site->id,
            'audience' => $audience, 'recipient_id' => null, 'email' => $email, 'template' => 'promotion',
            'provider' => 'mailgun', 'credential_id' => 7, 'credential_label' => 'Mailgun · Main',
            'from_address' => 'offers@example.com', 'status' => $status, 'error' => $status === 'failed' ? 'boom' : null,
        ];
        ScheduleSendHistory::record([
            $row('alice@example.com', 'mailgun_receivers', 'sent'),
            $row('bob@example.com', 'mailgun_receivers', 'failed'),
            $row('carol@example.com', 'warmup', 'sent'),
        ]);

        $this->getJson('/api/v1/admin/schedule-history?audience=mailgun_receivers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [[
                'email', 'schedule_name', 'site_name', 'audience_label', 'template',
                'provider', 'credential_label', 'from_address', 'status', 'error', 'sent_at',
            ]]]);
        $this->getJson('/api/v1/admin/schedule-history/count?status=failed')->assertExactJson(['total' => 1]);
        $this->getJson('/api/v1/admin/schedule-history?search=car')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.audience_label', 'Warmup');
        $this->getJson("/api/v1/admin/schedule-history/count?schedule_id={$schedule->id}&provider=mailgun")
            ->assertExactJson(['total' => 3]);
        $this->getJson('/api/v1/admin/schedule-history/schedules')
            ->assertExactJson(['data' => [['id' => $schedule->id, 'name' => 'Receivers blast']]]);

        // Deleting the schedule keeps its history, by the name it was sent under.
        $schedule->delete();
        $this->getJson('/api/v1/admin/schedule-history/count')->assertExactJson(['total' => 3]);
    }

    public function test_history_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/schedule-history')->assertUnauthorized();
    }
}
