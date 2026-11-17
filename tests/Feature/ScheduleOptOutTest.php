<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPromotionBatchJob;
use App\Jobs\SendScheduleListBatchJob;
use App\Mail\PromotionEmail;
use App\Models\EmailSchedule;
use App\Models\MailgunReceiver;
use App\Models\Newsletter;
use App\Models\Site;
use App\Models\Unsubscribe;
use App\Models\WarmupEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use App\Services\ScheduleRecipientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * An opt-out belongs to the SITE, not to the list or the template it was made
 * through: once someone unsubscribes from a site, nothing that site sends
 * reaches them again — on its own subscribers, on a Custom Receivers list their
 * address also sits on, or on warmup.
 *
 * Until 2026-11-17 only the `newsletters` audience checked it, so a schedule
 * could mail an opted-out person through a receivers list. These tests pin the
 * rule on every audience, in the audience query AND at send time.
 */
class ScheduleOptOutTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function schedule(Site $site, string $audience, array $attrs = []): EmailSchedule
    {
        return EmailSchedule::create([
            'site_id'       => $site->id,
            'name'          => 'Nightly',
            'audience'      => $audience,
            'limit'         => 50,
            'cooldown_days' => 1,
            'frequency'     => EmailSchedule::FREQ_DAILY,
            'time'          => '03:00',
            'active'        => true,
            ...$attrs,
        ]);
    }

    /** One address on all three lists, opted out of the site through the VERIFY mail. */
    private function optedOut(Site $site, string $email = 'gone@example.com'): string
    {
        Newsletter::create(['site_id' => $site->id, 'email' => $email, 'full_name' => 'Ann', 'verified_at' => now()]);
        MailgunReceiver::create(['email' => $email, 'name' => 'Ann', 'source' => 'manual']);
        WarmupEmail::create(['email' => $email]);
        // A DIFFERENT stream from the promotion a schedule sends: the opt-out
        // is global per site, so this must still stop it.
        Unsubscribe::record($site->id, $email, Unsubscribe::TYPE_VERIFY);

        return $email;
    }

    /** @return array<int, string> */
    private function audienceEmails(EmailSchedule $schedule): array
    {
        return app(ScheduleRecipientService::class)->sample($schedule, 50)->pluck('email')->all();
    }

    public function test_an_opted_out_address_is_out_of_every_audience(): void
    {
        [$site] = $this->siteWithKey();
        $gone = $this->optedOut($site);

        foreach ([
            EmailSchedule::AUDIENCE_NEWSLETTERS,
            EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS,
            EmailSchedule::AUDIENCE_WARMUP,
        ] as $audience) {
            $this->assertNotContains(
                $gone,
                $this->audienceEmails($this->schedule($site, $audience)),
                "{$audience} still offered an address that opted out of the site",
            );
        }
    }

    public function test_an_address_that_did_not_opt_out_is_still_in_every_audience(): void
    {
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'ann@example.com', 'full_name' => 'Ann', 'verified_at' => now()]);
        MailgunReceiver::create(['email' => 'ann@example.com', 'name' => 'Ann', 'source' => 'manual']);
        WarmupEmail::create(['email' => 'ann@example.com']);

        foreach ([
            EmailSchedule::AUDIENCE_NEWSLETTERS,
            EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS,
            EmailSchedule::AUDIENCE_WARMUP,
        ] as $audience) {
            $this->assertContains('ann@example.com', $this->audienceEmails($this->schedule($site, $audience)));
        }
    }

    /** An opt-out on ONE site must not silence another site. */
    public function test_the_opt_out_is_scoped_to_its_own_site(): void
    {
        [$siteA] = $this->siteWithKey();
        [$siteB] = $this->siteWithKey();
        $email = $this->optedOut($siteA);
        Newsletter::create(['site_id' => $siteB->id, 'email' => $email, 'full_name' => 'Ann', 'verified_at' => now()]);

        $this->assertNotContains($email, $this->audienceEmails($this->schedule($siteA, EmailSchedule::AUDIENCE_NEWSLETTERS)));
        $this->assertContains($email, $this->audienceEmails($this->schedule($siteB, EmailSchedule::AUDIENCE_NEWSLETTERS)));
    }

    /**
     * The send re-reads the opt-out, so someone who unsubscribes between the
     * fan-out and their batch is still dropped.
     */
    public function test_a_receiver_batch_skips_an_address_that_opted_out_after_fan_out(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $receiver = MailgunReceiver::create(['email' => 'gone@example.com', 'name' => 'Ann', 'source' => 'manual']);
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS);

        // The batch was queued while they were still subscribed.
        Unsubscribe::record($site->id, 'gone@example.com', Unsubscribe::TYPE_PROMOTION);

        (new SendScheduleListBatchJob(
            $schedule->id, $site->id, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS, [$receiver->id],
            EmailSchedule::PROVIDER_SMTP, null,
        ))->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertNothingSent();
        $this->assertDatabaseHas('schedule_send_histories', [
            'email_schedule_id' => $schedule->id,
            'email'             => 'gone@example.com',
            'status'            => 'skipped',
        ]);
    }

    public function test_a_newsletter_batch_skips_an_address_that_opted_out_after_fan_out(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'gone@example.com', 'full_name' => 'Ann', 'verified_at' => now()]);
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_NEWSLETTERS);
        Unsubscribe::record($site->id, 'gone@example.com', Unsubscribe::TYPE_SUBSCRIPTION);

        (new SendPromotionBatchJob($site->id, ['gone@example.com'], EmailSchedule::PROVIDER_SMTP, null, null, $schedule->id))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertNothingSent();
    }

    // ── The deliberate override ─────────────────────────────────────────────

    public function test_include_unsubscribed_is_off_by_default(): void
    {
        [$site] = $this->siteWithKey();

        $this->assertFalse($this->schedule($site, EmailSchedule::AUDIENCE_NEWSLETTERS)->includesUnsubscribed());
        $this->assertTrue($this->schedule($site, EmailSchedule::AUDIENCE_NEWSLETTERS)->honoursOptOut());
    }

    public function test_include_unsubscribed_puts_them_back_in_the_audience(): void
    {
        [$site] = $this->siteWithKey();
        $gone = $this->optedOut($site);

        foreach ([EmailSchedule::AUDIENCE_NEWSLETTERS, EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS] as $audience) {
            $schedule = $this->schedule($site, $audience, ['include_unsubscribed' => true]);
            $this->assertContains($gone, $this->audienceEmails($schedule));
        }
    }

    public function test_include_unsubscribed_also_lets_the_send_through(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'gone@example.com', 'full_name' => 'Ann', 'verified_at' => now()]);
        Unsubscribe::record($site->id, 'gone@example.com', Unsubscribe::TYPE_PROMOTION);
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_NEWSLETTERS, ['include_unsubscribed' => true]);

        (new SendPromotionBatchJob($site->id, ['gone@example.com'], EmailSchedule::PROVIDER_SMTP, null, null, $schedule->id))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class, fn (PromotionEmail $mail): bool => $mail->hasTo('gone@example.com'));
    }

    /** A batch with no schedule behind it — the admin's send-to-all — never skips the opt-out. */
    public function test_a_batch_without_a_schedule_always_honours_the_opt_out(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'gone@example.com', 'full_name' => 'Ann', 'verified_at' => now()]);
        Unsubscribe::record($site->id, 'gone@example.com', Unsubscribe::TYPE_PROMOTION);

        (new SendPromotionBatchJob($site->id, ['gone@example.com'], EmailSchedule::PROVIDER_SMTP))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertNothingSent();
    }

    // ── Warmup's cooldown ───────────────────────────────────────────────────

    /**
     * A schedule sending to the warmup list is real traffic to those mailboxes,
     * so it has to start their warmup cooldown exactly as a warmup run does.
     */
    public function test_a_schedule_send_to_warmup_stamps_last_sent_at(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $seed = WarmupEmail::create(['email' => 'seed@example.com']);
        $this->assertNull($seed->last_sent_at);

        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_WARMUP);

        (new SendScheduleListBatchJob(
            $schedule->id, $site->id, EmailSchedule::AUDIENCE_WARMUP, [$seed->id],
            EmailSchedule::PROVIDER_SMTP, null,
        ))->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(PromotionEmail::class);
        $this->assertNotNull($seed->fresh()->last_sent_at, 'a schedule send did not start the warmup cooldown');
    }

    /** A skipped address was never mailed, so its cooldown must not start. */
    public function test_a_skipped_warmup_address_keeps_its_last_sent_at(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $seed = WarmupEmail::create(['email' => 'gone@example.com']);
        Unsubscribe::record($site->id, 'gone@example.com', Unsubscribe::TYPE_PROMOTION);
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_WARMUP);

        (new SendScheduleListBatchJob(
            $schedule->id, $site->id, EmailSchedule::AUDIENCE_WARMUP, [$seed->id],
            EmailSchedule::PROVIDER_SMTP, null,
        ))->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertNothingSent();
        $this->assertNull($seed->fresh()->last_sent_at);
    }
}
