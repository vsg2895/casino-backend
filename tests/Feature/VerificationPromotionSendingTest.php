<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendVerificationPromotionJob;
use App\Mail\PostVerificationPromotionEmail;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\Unsubscribe;
use App\Models\VerificationPostVerificationPromotionEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Locks the Promotion After Verification sending contract.
 *
 *  - transport is the .env SendGrid key (config('mail.public_mailer')), the same
 *    one a visitor's subscribe/verify mail goes through — never the admin SMTP
 *    mailer and never a stored key;
 *  - the From address is the one configured in THIS section's template, not the
 *    .env SMTP mailbox and not the shared public sender;
 *  - Send Test behaves identically to the real send, and works whether the
 *    feature is enabled or disabled;
 *  - the real send only fires once the configured delay has elapsed since
 *    newsletters.verified_at, and only once per subscriber.
 */
class VerificationPromotionSendingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** The address configured in the Promotion After Verification section. */
    private const string SECTION_FROM = 'offers@winpalack.test';

    /** The .env SMTP mailbox — must never be the sender for this feature. */
    private const string SMTP_FROM = 'smtp@fmcsafiling.test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.public_mailer', 'sendgrid');
        config()->set('mail.admin_test_mailer', 'smtp');
        config()->set('mail.from.address', self::SMTP_FROM);
        // A non-empty key so the .env SendGrid provider resolves.
        config()->set('mail.mailers.sendgrid', ['transport' => 'array', 'key' => 'SG.test-key']);
        // The shared public sender, which this feature must NOT adopt.
        config()->set('mail.public_from_address', 'info@winpalack.test');
    }

    private function configureSection(bool $active = true, int $delayMinutes = 5): VerificationPostVerificationPromotionEmail
    {
        $config = VerificationPostVerificationPromotionEmail::current();
        $config->update([
            'active'          => $active,
            'delay_minutes'   => $delayMinutes,
            'provider'        => EmailSchedule::PROVIDER_SENDGRID_ENV,
            'sendgrid_key_id' => null,
            'mailgun_key_id'  => null,
            'from_email'      => self::SECTION_FROM,
            'from_name'       => 'Winpalack',
        ]);

        return $config->refresh();
    }

    /** A subscriber who verified far enough in the past to be eligible. */
    private function eligibleSubscriber(int $verifiedMinutesAgo = 10): Newsletter
    {
        [$site] = $this->siteWithKey();

        $newsletter = new Newsletter([
            'site_id'  => $site->id,
            'email'    => 'subscriber@example.test',
            'verified' => true,
        ]);
        $newsletter->save();

        Newsletter::whereKey($newsletter->id)->update([
            'verified_at' => Carbon::now()->subMinutes($verifiedMinutesAgo),
        ]);

        return $newsletter->refresh();
    }

    // ── The real automatic send ──────────────────────────────────────────────

    public function test_real_send_uses_env_sendgrid_and_the_section_from_address(): void
    {
        Mail::fake();
        $this->configureSection();
        $newsletter = $this->eligibleSubscriber();

        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        Mail::assertSent(PostVerificationPromotionEmail::class, function (PostVerificationPromotionEmail $mail): bool {
            $envelope = $mail->envelope();

            return $mail->mailer === 'sendgrid'
                && $envelope->from?->address === self::SECTION_FROM;
        });
    }

    public function test_real_send_never_uses_the_smtp_mailbox_as_sender(): void
    {
        Mail::fake();
        $this->configureSection();
        $newsletter = $this->eligibleSubscriber();

        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        Mail::assertSent(PostVerificationPromotionEmail::class, function (PostVerificationPromotionEmail $mail): bool {
            return $mail->envelope()->from?->address !== self::SMTP_FROM;
        });
    }

    public function test_real_send_is_skipped_while_the_feature_is_disabled(): void
    {
        Mail::fake();
        $this->configureSection(active: false);
        $newsletter = $this->eligibleSubscriber();

        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        Mail::assertNothingSent();
        $this->assertNull($newsletter->refresh()->verification_promotion_sent_at);
    }

    public function test_real_send_waits_until_the_delay_since_verified_at_has_elapsed(): void
    {
        Mail::fake();
        $this->configureSection(delayMinutes: 60);
        // Verified 5 minutes ago — inside a 60 minute delay.
        $newsletter = $this->eligibleSubscriber(verifiedMinutesAgo: 5);

        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        Mail::assertNothingSent();
    }

    public function test_real_send_happens_at_most_once_per_subscriber(): void
    {
        Mail::fake();
        $this->configureSection();
        $newsletter = $this->eligibleSubscriber();

        SendVerificationPromotionJob::dispatchSync($newsletter->id);
        SendVerificationPromotionJob::dispatchSync($newsletter->id);
        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        Mail::assertSent(PostVerificationPromotionEmail::class, 1);
        $this->assertNotNull($newsletter->refresh()->verification_promotion_sent_at);
    }

    public function test_real_send_respects_a_promotion_opt_out(): void
    {
        Mail::fake();
        $this->configureSection();
        $newsletter = $this->eligibleSubscriber();

        Unsubscribe::create([
            'site_id'         => $newsletter->site_id,
            'email'           => $newsletter->email,
            'type'            => Unsubscribe::TYPE_PROMOTION,
            'unsubscribed_at' => Carbon::now(),
        ]);

        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        Mail::assertNothingSent();
    }

    // ── The admin "Send test" button ─────────────────────────────────────────

    public function test_send_test_uses_env_sendgrid_and_the_section_from_address(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $this->siteWithKey();
        $this->configureSection();

        $this->postJson('/api/v1/admin/verification-promotion/test', ['to' => 'admin@example.test'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Mail::assertSent(PostVerificationPromotionEmail::class, function (PostVerificationPromotionEmail $mail): bool {
            return $mail->mailer === 'sendgrid'
                && $mail->envelope()->from?->address === self::SECTION_FROM;
        });
    }

    public function test_send_test_works_while_the_feature_is_disabled(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $this->siteWithKey();
        $this->configureSection(active: false);

        $this->postJson('/api/v1/admin/verification-promotion/test', ['to' => 'admin@example.test'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        // The Enable switch governs the AUTOMATIC send only; the test button
        // must stay usable while the feature is paused.
        Mail::assertSent(PostVerificationPromotionEmail::class, 1);
    }

    public function test_send_test_matches_the_real_send_transport_and_sender(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $this->siteWithKey();
        $this->configureSection();
        $newsletter = $this->eligibleSubscriber();

        $this->postJson('/api/v1/admin/verification-promotion/test', ['to' => 'admin@example.test'])
            ->assertOk();
        SendVerificationPromotionJob::dispatchSync($newsletter->id);

        $senders = [];
        $mailers = [];
        Mail::assertSent(PostVerificationPromotionEmail::class, function (PostVerificationPromotionEmail $mail) use (&$senders, &$mailers): bool {
            $senders[] = $mail->envelope()->from?->address;
            $mailers[] = $mail->mailer;

            return true;
        });

        $this->assertCount(2, $senders, 'expected the test send and the real send');
        $this->assertSame([self::SECTION_FROM, self::SECTION_FROM], $senders);
        $this->assertSame(['sendgrid', 'sendgrid'], $mailers);
    }

    public function test_send_test_sends_the_promotion_template_not_the_verify_template(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        $this->siteWithKey();
        $this->configureSection();

        $this->postJson('/api/v1/admin/verification-promotion/test', ['to' => 'admin@example.test'])
            ->assertOk();

        Mail::assertSent(PostVerificationPromotionEmail::class);
        Mail::assertNotSent(\App\Mail\VerifyEmailMail::class);
    }
}
