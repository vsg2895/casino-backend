<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPromotionBatchJob;
use App\Jobs\SendScheduleListBatchJob;
use App\Mail\NewsletterSubscribedMail;
use App\Mail\PromotionEmail;
use App\Mail\ScheduleCustomMessage;
use App\Mail\VerifyEmailMail;
use App\Models\EmailSchedule;
use App\Models\MailgunReceiver;
use App\Models\Newsletter;
use App\Models\Site;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * A schedule chooses WHICH email it sends: one of the site's stored templates
 * (promotion, subscribe, verify) or the operator's own HTML.
 *
 * The promotion template is the default and must behave exactly as it did
 * before the choice existed — which is what the first test pins.
 */
class ScheduleTemplateChoiceTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function schedule(Site $site, array $attrs = []): EmailSchedule
    {
        return EmailSchedule::create([
            'site_id'       => $site->id,
            'name'          => 'Nightly',
            'audience'      => EmailSchedule::AUDIENCE_NEWSLETTERS,
            'limit'         => 100,
            'cooldown_days' => 1,
            'frequency'     => EmailSchedule::FREQ_DAILY,
            'time'          => '03:00',
            'active'        => true,
            ...$attrs,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function customSchedule(Site $site, array $attrs = []): EmailSchedule
    {
        return $this->schedule($site, [
            'template'            => EmailSchedule::TEMPLATE_CUSTOM,
            'custom_from_name'    => 'Casino Weekly',
            'custom_from_address' => 'offers@example.com',
            'custom_subject'      => 'This week in bonuses',
            'custom_html'         => '<p>Hello there, here is the week.</p>',
            ...$attrs,
        ]);
    }

    private function subscriber(Site $site, string $email): Newsletter
    {
        return Newsletter::create([
            'site_id'     => $site->id,
            'email'       => $email,
            'full_name'   => 'Ann',
            'verified_at' => now(),
        ]);
    }

    private function runNewsletterBatch(EmailSchedule $schedule, Site $site, string $email): void
    {
        (new SendPromotionBatchJob($site->id, [$email], EmailSchedule::PROVIDER_SMTP, null, null, $schedule->id))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));
    }

    public function test_a_schedule_defaults_to_the_promotion_template(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');
        // No `template` key at all: a row written before the column existed.
        $schedule = $this->schedule($site);

        $this->assertSame(EmailSchedule::TEMPLATE_PROMOTION, $schedule->templateKey());

        $this->runNewsletterBatch($schedule, $site, 'ann@example.com');

        Mail::assertSent(PromotionEmail::class);
        $this->assertDatabaseHas('schedule_send_histories', [
            'email_schedule_id' => $schedule->id,
            'email'             => 'ann@example.com',
            'template'          => EmailSchedule::TEMPLATE_PROMOTION,
            'status'            => 'sent',
        ]);
        // The promotion stream's own 24h record is still written for it.
        $this->assertDatabaseHas('promotion_email_histories', ['site_id' => $site->id, 'email' => 'ann@example.com']);
    }

    public function test_a_subscribe_schedule_sends_the_sites_welcome_template(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');
        $schedule = $this->schedule($site, ['template' => EmailSchedule::TEMPLATE_SUBSCRIBE]);

        $this->runNewsletterBatch($schedule, $site, 'ann@example.com');

        Mail::assertSent(NewsletterSubscribedMail::class, fn (NewsletterSubscribedMail $mail): bool => $mail->hasTo('ann@example.com'));
        Mail::assertNotSent(PromotionEmail::class);

        $this->assertDatabaseHas('schedule_send_histories', [
            'email_schedule_id' => $schedule->id,
            'email'             => 'ann@example.com',
            'template'          => EmailSchedule::TEMPLATE_SUBSCRIBE,
            'status'            => 'sent',
        ]);
    }

    public function test_a_verify_schedule_sends_the_sites_verify_template(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');
        $schedule = $this->schedule($site, ['template' => EmailSchedule::TEMPLATE_VERIFY]);

        $this->runNewsletterBatch($schedule, $site, 'ann@example.com');

        Mail::assertSent(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo('ann@example.com'));
        $this->assertDatabaseHas('schedule_send_histories', [
            'email_schedule_id' => $schedule->id,
            'template'          => EmailSchedule::TEMPLATE_VERIFY,
            'status'            => 'sent',
        ]);
    }

    /**
     * `promotion_email_histories` is the promotion stream's own 24-hour record —
     * the after-verification offer dedups against it too. Writing a subscribe or
     * custom send there would make the next promotion run skip everyone it
     * reached.
     */
    public function test_a_non_promotion_send_stays_out_of_the_promotion_history(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');

        $this->runNewsletterBatch($this->customSchedule($site), $site, 'ann@example.com');

        $this->assertDatabaseMissing('promotion_email_histories', ['site_id' => $site->id, 'email' => 'ann@example.com']);
        $this->assertDatabaseHas('schedule_send_histories', [
            'email'    => 'ann@example.com',
            'template' => EmailSchedule::TEMPLATE_CUSTOM,
            'status'   => 'sent',
        ]);
    }

    public function test_a_custom_schedule_sends_its_own_subject_body_and_from_pair(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');
        $schedule = $this->customSchedule($site);

        $this->runNewsletterBatch($schedule, $site, 'ann@example.com');

        Mail::assertSent(ScheduleCustomMessage::class, function (ScheduleCustomMessage $mail): bool {
            $envelope = $mail->envelope();

            return $mail->hasTo('ann@example.com')
                && $envelope->subject === 'This week in bonuses'
                && $envelope->from?->address === 'offers@example.com'
                && $envelope->from?->name === 'Casino Weekly'
                // A real opt-out, not warmup's inert mailto:.
                && str_contains($mail->oneClickUrl, '/api/v1/unsubscribe/');
        });

        $this->assertDatabaseHas('schedule_send_histories', [
            'email_schedule_id' => $schedule->id,
            'template'          => EmailSchedule::TEMPLATE_CUSTOM,
            'from_address'      => 'offers@example.com',
            'status'            => 'sent',
        ]);
    }

    public function test_a_custom_send_appends_an_unsubscribe_link_when_the_html_has_none(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');

        $this->runNewsletterBatch($this->customSchedule($site), $site, 'ann@example.com');

        Mail::assertSent(ScheduleCustomMessage::class, function (ScheduleCustomMessage $mail): bool {
            $html = $mail->render();

            return str_contains($html, '/unsubscribe/') && str_contains($html, 'Unsubscribe');
        });
    }

    /** A body that already offers a way out keeps its own; no second footer. */
    public function test_a_custom_send_does_not_append_a_second_unsubscribe_link(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');
        $schedule = $this->customSchedule($site, [
            'custom_html' => '<p>Hello.</p><p><a href="https://example.com/out">Unsubscribe</a></p>',
        ]);

        $this->runNewsletterBatch($schedule, $site, 'ann@example.com');

        Mail::assertSent(ScheduleCustomMessage::class, fn (ScheduleCustomMessage $mail): bool
            => substr_count(strtolower($mail->render()), 'unsubscribe<') <= 1);
    }

    public function test_a_custom_schedule_also_mails_a_receiver_list_with_that_lists_opt_out(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $receiver = MailgunReceiver::create(['email' => 'person@example.com', 'name' => 'Ann', 'source' => 'manual']);
        $schedule = $this->customSchedule($site, ['audience' => EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS]);

        (new SendScheduleListBatchJob(
            $schedule->id,
            $site->id,
            EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS,
            [$receiver->id],
            EmailSchedule::PROVIDER_SMTP,
            null,
        ))->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSent(ScheduleCustomMessage::class, fn (ScheduleCustomMessage $mail): bool
            => $mail->hasTo('person@example.com') && $mail->oneClickUrl === $receiver->unsubscribeUrl());
        Mail::assertNotSent(PromotionEmail::class);
    }

    /** A custom schedule missing half its content must send nothing at all. */
    public function test_a_custom_schedule_without_a_body_sends_nothing(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');
        $schedule = $this->customSchedule($site, ['custom_html' => null]);

        $this->runNewsletterBatch($schedule, $site, 'ann@example.com');

        Mail::assertNothingSent();
        $this->assertDatabaseCount('schedule_send_histories', 0);
    }

    /**
     * The cooldown rests an address per TEMPLATE. A site running a promotion
     * schedule and a custom one must not have the first silently suppress the
     * second.
     */
    public function test_the_cooldown_is_scoped_to_the_template(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');

        $this->runNewsletterBatch($this->schedule($site, ['name' => 'Promo']), $site, 'ann@example.com');
        Mail::assertSent(PromotionEmail::class);

        // Same address, same day, 30-day cooldown — but a different template.
        $custom = $this->customSchedule($site, ['name' => 'Custom', 'cooldown_days' => 30]);
        $this->runNewsletterBatch($custom, $site, 'ann@example.com');

        Mail::assertSent(ScheduleCustomMessage::class);
        $this->assertDatabaseHas('schedule_send_histories', [
            'email_schedule_id' => $custom->id,
            'template'          => EmailSchedule::TEMPLATE_CUSTOM,
            'status'            => 'sent',
        ]);
    }

    /** The template a schedule sends has to be switched on for its site. */
    public function test_a_schedule_whose_template_is_switched_off_sends_nothing(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $this->subscriber($site, 'ann@example.com');
        $site->emailTemplateOrDefault()->forceFill(['active' => false])->save();

        $this->runNewsletterBatch($this->schedule($site, ['template' => EmailSchedule::TEMPLATE_SUBSCRIBE]), $site, 'ann@example.com');

        Mail::assertNothingSent();
    }

    // ── Validation ──────────────────────────────────────────────────────────

    public function test_the_api_accepts_a_custom_schedule_and_clears_it_when_the_template_changes(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $payload = [
            'site_id'             => $site->id,
            'template'            => EmailSchedule::TEMPLATE_CUSTOM,
            'custom_from_name'    => 'Casino Weekly',
            'custom_from_address' => 'offers@example.com',
            'custom_subject'      => 'This week in bonuses',
            'custom_html'         => '<p>Hello.</p>',
            'audience'            => EmailSchedule::AUDIENCE_NEWSLETTERS,
            'limit'               => 100,
            'cooldown_days'       => 1,
            'frequency'           => EmailSchedule::FREQ_DAILY,
            'time'                => '03:00',
            'provider'            => EmailSchedule::PROVIDER_SMTP,
        ];

        $created = $this->postJson('/api/v1/admin/schedules', $payload)->assertCreated();
        $id = $created->json('data.id');

        $this->assertDatabaseHas('email_schedules', [
            'id'                  => $id,
            'template'            => EmailSchedule::TEMPLATE_CUSTOM,
            'custom_from_address' => 'offers@example.com',
            'custom_subject'      => 'This week in bonuses',
        ]);

        // Switching to a stored template drops the custom content, so it can
        // never be sent by a schedule that no longer means to.
        $this->putJson("/api/v1/admin/schedules/{$id}", [...$payload, 'template' => EmailSchedule::TEMPLATE_PROMOTION])
            ->assertOk();

        $this->assertDatabaseHas('email_schedules', [
            'id'                  => $id,
            'template'            => EmailSchedule::TEMPLATE_PROMOTION,
            'custom_subject'      => null,
            'custom_html'         => null,
            'custom_from_address' => null,
        ]);
    }

    public function test_custom_html_requires_its_four_fields(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id'       => $site->id,
            'template'      => EmailSchedule::TEMPLATE_CUSTOM,
            'audience'      => EmailSchedule::AUDIENCE_NEWSLETTERS,
            'limit'         => 100,
            'cooldown_days' => 1,
            'frequency'     => EmailSchedule::FREQ_DAILY,
            'time'          => '03:00',
            'provider'      => EmailSchedule::PROVIDER_SMTP,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'custom_from_name', 'custom_from_address', 'custom_subject', 'custom_html',
        ]);
    }

    /**
     * The SUBSCRIPTION template may be paired with every audience; the VERIFY
     * template may not.
     *
     * Both were refused on anything but Newsletters until 2026-12, because both
     * are rendered from the recipient's own token and a Custom Receivers or
     * warmup address had none. Every global list now carries one, so the
     * welcome message's opt-out resolves to the list that mailed them and the
     * pair is valid.
     *
     * Verify stays newsletters-only for a reason tokens do not fix: it asks the
     * recipient to confirm an address they submitted, and every address on a
     * global list was entered by an operator who already has it. There is no
     * claim to confirm, so the button would have nothing to do.
     *
     * {@see \Tests\Feature\ListMemberTokensTest} is what proves the links the
     * accepted pairs produce actually resolve.
     */
    public function test_subscribe_reaches_every_audience_and_verify_only_newsletters(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $lists = [
            EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS,
            EmailSchedule::AUDIENCE_SENDGRID_RECEIVERS,
            EmailSchedule::AUDIENCE_WARMUP,
        ];

        foreach ([EmailSchedule::AUDIENCE_NEWSLETTERS, ...$lists] as $audience) {
            $this->postJson('/api/v1/admin/schedules', $this->payload($site, EmailSchedule::TEMPLATE_SUBSCRIBE, $audience))
                ->assertCreated();

            $this->assertDatabaseHas('email_schedules', [
                'site_id'  => $site->id,
                'template' => EmailSchedule::TEMPLATE_SUBSCRIBE,
                'audience' => $audience,
            ]);
        }

        $this->postJson('/api/v1/admin/schedules', $this->payload($site, EmailSchedule::TEMPLATE_VERIFY, EmailSchedule::AUDIENCE_NEWSLETTERS))
            ->assertCreated();

        foreach ($lists as $audience) {
            $this->postJson('/api/v1/admin/schedules', $this->payload($site, EmailSchedule::TEMPLATE_VERIFY, $audience))
                ->assertStatus(422)
                ->assertJsonValidationErrors('audience');

            $this->assertDatabaseMissing('email_schedules', [
                'template' => EmailSchedule::TEMPLATE_VERIFY,
                'audience' => $audience,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Site $site, string $template, string $audience): array
    {
        return [
            'site_id'       => $site->id,
            'template'      => $template,
            'audience'      => $audience,
            'limit'         => 100,
            'cooldown_days' => 1,
            'frequency'     => EmailSchedule::FREQ_DAILY,
            'time'          => '03:00',
            'provider'      => EmailSchedule::PROVIDER_SMTP,
        ];
    }
}
