<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWarmupCampaignJob;
use App\Mail\NewsletterSubscribedMail;
use App\Models\Newsletter;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\EmailTemplateCatalog;
use App\Services\Mail\WarmupMailResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Sending a warmup run: transport, history and the cooldown stamp.
 *
 * NO REAL EMAIL LEAVES THIS FILE. Transport assertions use Mail::fake(); the
 * delivery-outcome tests swap in a local no-op transport whose doSend() either
 * does nothing or throws, so the full Mailer → Mailable → Blade path really runs
 * while nothing reaches the network. The database is the in-memory SQLite from
 * phpunit.xml, so the real one is never opened.
 *
 * The queue is `sync` under test, so posting to the send endpoint runs the
 * fan-out and its batches inline.
 */
class WarmupSendTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addAddress(string $email, ?string $lastSentAt = null): WarmupEmail
    {
        $row = WarmupEmail::create(['email' => $email]);
        $row->forceFill(['last_sent_at' => $lastSentAt === null ? null : Carbon::parse($lastSentAt)])->save();

        return $row->refresh();
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload)
    {
        return $this->postJson('/api/v1/admin/warmup-emails/send', $payload);
    }

    /**
     * A transport that never touches the network.
     *
     * Addresses beginning "bad" throw, so a partial failure can be exercised
     * against the REAL mailer rather than a mock — which is the only way to prove
     * the batch survives one rejected recipient.
     */
    private function useLocalTransport(): void
    {
        Mail::extend('warmup-local', fn (array $config): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                    if (str_starts_with($recipient->getAddress(), 'bad')) {
                        throw new RuntimeException('550 mailbox unavailable');
                    }
                }
            }

            public function __toString(): string
            {
                return 'warmup-local://';
            }
        });

        config()->set('mail.mailers.warmup-local', ['transport' => 'warmup-local']);
        config()->set('warmup.mailer', 'warmup-local');
    }

    // ── Transport: SMTP only, never SendGrid or Mailgun ──────────────────────

    public function test_warmup_always_sends_over_the_smtp_mailer(): void
    {
        // The rest of the platform is pointed at SendGrid/Mailgun. Warmup exists
        // to build the reputation of the .env SMTP mailbox, so routing it through
        // a provider would warm that provider's shared infrastructure instead and
        // silently defeat the whole feature.
        config()->set('mail.public_mailer', 'sendgrid');
        config()->set('mail.admin_mailer', 'sendgrid');
        config()->set('mail.default', 'sendgrid');

        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('seed@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted()->assertJson(['ok' => true]);

        Mail::assertSent(
            NewsletterSubscribedMail::class,
            fn (NewsletterSubscribedMail $mail): bool => $mail->mailer === 'smtp',
        );
    }

    public function test_warmup_mailer_is_a_literal_and_ignores_the_mail_env(): void
    {
        // config/warmup.php pins 'smtp' as a literal rather than an env() lookup,
        // precisely so flipping a MAIL_* variable cannot drag warmup along.
        $this->assertSame('smtp', config('warmup.mailer'));

        config()->set('mail.admin_mailer', 'mailgun');
        config()->set('mail.public_mailer', 'mailgun');

        $this->assertSame('smtp', config('warmup.mailer'));
    }

    public function test_warmup_uses_the_configured_from_address(): void
    {
        config()->set('mail.from.address', 'smtp@authenticated.test');

        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $template = $site->emailTemplateOrDefault();
        $this->addAddress('seed@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted();

        Mail::assertSent(NewsletterSubscribedMail::class, function (NewsletterSubscribedMail $mail) use ($template): bool {
            $from = $mail->envelope()->from;

            // From address is the authenticated mailbox; the display name still
            // comes from the site's own template.
            return $from?->address === 'smtp@authenticated.test'
                && $from?->name === $template->from_name;
        });
    }

    // ── History ──────────────────────────────────────────────────────────────

    public function test_history_records_address_site_template_and_time(): void
    {
        $this->useLocalTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('seed@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted();

        $row = WarmupSendRecipient::sole();

        $this->assertSame('seed@example.com', $row->email);
        $this->assertSame($site->id, $row->site_id);
        $this->assertSame(EmailTemplateCatalog::TYPE_SUBSCRIBE, $row->template);
        $this->assertSame(WarmupSendRecipient::STATUS_SENT, $row->status);
        $this->assertNull($row->error);
        $this->assertNotNull($row->sent_at);
        // Linked back to both the run header and the address row.
        $this->assertSame(WarmupSend::sole()->id, $row->warmup_send_id);
        $this->assertSame(WarmupEmail::sole()->id, $row->warmup_email_id);
    }

    public function test_successful_send_advances_the_cooldown_stamp(): void
    {
        $this->useLocalTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $address = $this->addAddress('seed@example.com');

        $this->assertNull($address->last_sent_at);

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted();

        $this->assertNotNull($address->refresh()->last_sent_at);
    }

    public function test_failed_send_is_recorded_but_does_not_start_a_cooldown(): void
    {
        // A permanently broken address must stay eligible for the next run rather
        // than serving an N-day cooldown it never earned.
        $this->useLocalTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $address = $this->addAddress('bad@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted();

        $row = WarmupSendRecipient::sole();
        $this->assertSame(WarmupSendRecipient::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('550', (string) $row->error);

        $this->assertNull($address->refresh()->last_sent_at, 'a failure must not stamp the cooldown');
    }

    public function test_one_failing_address_does_not_abort_the_batch(): void
    {
        $this->useLocalTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('good1@example.com');
        $this->addAddress('bad@example.com');
        $this->addAddress('good2@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted();

        $this->assertSame(3, WarmupSendRecipient::count(), 'every attempt is recorded');
        $this->assertSame(2, WarmupSendRecipient::where('status', WarmupSendRecipient::STATUS_SENT)->count());
        $this->assertSame(1, WarmupSendRecipient::where('status', WarmupSendRecipient::STATUS_FAILED)->count());

        // Only the delivered addresses advanced.
        $this->assertNull(WarmupEmail::where('email', 'bad@example.com')->sole()->last_sent_at);
        $this->assertNotNull(WarmupEmail::where('email', 'good2@example.com')->sole()->last_sent_at);
    }

    public function test_warmup_never_creates_newsletter_subscribers(): void
    {
        // WarmupMailResolver deliberately avoids EmailTemplateCatalog::build(),
        // which needs a Newsletter and would firstOrCreate() one — inserting every
        // seed address into the site's real audience and corrupting every future
        // campaign. This is the regression guard for that.
        $this->useLocalTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('seed@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted();

        $this->assertSame(0, Newsletter::count());
    }

    public function test_history_endpoint_filters_by_status(): void
    {
        $this->useLocalTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('good@example.com');
        $this->addAddress('bad@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted();

        $this->getJson('/api/v1/admin/warmup-emails/history')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/admin/warmup-emails/history?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'bad@example.com');

        $this->getJson('/api/v1/admin/warmup-emails/history/count?status=sent')
            ->assertOk()
            ->assertJson(['total' => 1]);
    }

    public function test_the_site_template_is_read_once_per_batch_not_once_per_recipient(): void
    {
        // WarmupMailResolver memoises the resolved template for the batch. Without
        // that, `…OrDefault()` ran per address: one SELECT and one hydrated model
        // for every recipient, so a 100-address batch paid 100 redundant reads and
        // left 100 model graphs behind. This is the guard against that returning.
        $this->useLocalTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // Create the row up front so the batch can only ever READ it.
        $site->emailTemplateOrDefault();

        foreach (range(1, 5) as $i) {
            $this->addAddress("seed{$i}@example.com");
        }

        $reads = 0;
        DB::listen(function ($query) use (&$reads): void {
            if (
                str_contains($query->sql, 'site_email_templates')
                && str_starts_with(strtolower(ltrim($query->sql)), 'select')
            ) {
                $reads++;
            }
        });

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertAccepted();

        $this->assertSame(5, WarmupSendRecipient::count(), 'all five were still mailed');
        $this->assertSame(1, $reads, 'the template must be read once for the batch, not once per recipient');
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    public function test_a_second_run_is_rejected_while_one_is_in_flight(): void
    {
        // Two concurrent runs would mail the same seed addresses twice. The guard
        // is a cross-process lock, not a disabled button, so it holds across tabs
        // and app servers.
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('seed@example.com');

        Cache::lock(SendWarmupCampaignJob::runLockKey(), 900)->get();

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
        ])->assertStatus(409)->assertJson(['ok' => false]);

        Mail::assertNothingSent();
    }

    public function test_every_catalogued_template_can_be_used_for_warmup(): void
    {
        // All four site templates are selectable. VERIFY is included at the
        // operator's request despite its caveat (its confirmation link means
        // nothing for a seed address) — see WarmupMailResolver::ALLOWED_TEMPLATES.
        $this->useLocalTransport();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $types = [
            EmailTemplateCatalog::TYPE_SUBSCRIBE,
            EmailTemplateCatalog::TYPE_PROMOTION,
            EmailTemplateCatalog::TYPE_PROMOTION_AFTER_VERIFICATION,
            EmailTemplateCatalog::TYPE_VERIFY,
        ];

        foreach ($types as $type) {
            WarmupEmail::query()->delete();
            Cache::lock(SendWarmupCampaignJob::runLockKey(), 1)->forceRelease();
            $this->addAddress("seed-{$type}@example.com");

            $this->send([
                'site_id'  => $site->id,
                'template' => $type,
            ])->assertAccepted();

            $this->assertSame(
                WarmupSendRecipient::STATUS_SENT,
                WarmupSendRecipient::where('template', $type)->sole()->status,
                "{$type} should render and send",
            );
        }
    }

    public function test_the_templates_endpoint_offers_all_four(): void
    {
        $this->actingAsAdmin();

        $values = collect($this->getJson('/api/v1/admin/warmup-emails/templates')->assertOk()->json('data'))
            ->pluck('value')
            ->all();

        $this->assertEqualsCanonicalizing(WarmupMailResolver::ALLOWED_TEMPLATES, $values);
        $this->assertContains(EmailTemplateCatalog::TYPE_PROMOTION_AFTER_VERIFICATION, $values);
        $this->assertContains(EmailTemplateCatalog::TYPE_VERIFY, $values);
    }

    public function test_an_unknown_template_is_still_rejected(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('seed@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => 'not_a_real_template',
        ])->assertStatus(422)->assertJsonValidationErrors('template');

        Mail::assertNothingSent();
    }

    public function test_the_send_dialog_defaults_to_the_configured_site(): void
    {
        // The slug lives in config, not in the admin bundle, so the preselected
        // site is an operator setting rather than a brand name baked into the SPA.
        $this->actingAsAdmin();
        $this->siteWithKey(['slug' => 'first-site', 'name' => 'First Site']);
        [$preferred] = $this->siteWithKey(['slug' => 'winpalack', 'name' => 'Winpalack']);

        config()->set('warmup.default_site_slug', 'winpalack');

        $this->getJson('/api/v1/admin/warmup-emails/recipients')
            ->assertOk()
            ->assertJsonPath('data.default_site_id', $preferred->id);
    }

    public function test_an_unknown_default_site_slug_falls_back_to_no_preference(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey();

        config()->set('warmup.default_site_slug', 'a-site-that-does-not-exist');

        $this->getJson('/api/v1/admin/warmup-emails/recipients')
            ->assertOk()
            ->assertJsonPath('data.default_site_id', null);
    }

    public function test_cooldown_must_be_within_one_and_three_hundred_and_sixty_five_days(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('seed@example.com');

        foreach ([0, 366] as $invalid) {
            $this->send([
                'site_id'       => $site->id,
                'template'      => EmailTemplateCatalog::TYPE_SUBSCRIBE,
                'count'         => 1,
                'cooldown_days' => $invalid,
            ])->assertStatus(422)->assertJsonValidationErrors('cooldown_days');
        }

        foreach ([1, 365] as $valid) {
            $this->send([
                'site_id'       => $site->id,
                'template'      => EmailTemplateCatalog::TYPE_SUBSCRIBE,
                'count'         => 1,
                'cooldown_days' => $valid,
            ])->assertAccepted();

            Cache::lock(SendWarmupCampaignJob::runLockKey(), 1)->forceRelease();
            WarmupEmail::query()->update(['last_sent_at' => null]);
        }
    }

    public function test_count_larger_than_the_list_is_rejected(): void
    {
        // A typo guard. Validated against the LIST size, never the eligible set —
        // asking for 50 when a cooldown leaves 12 eligible is a valid request.
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('seed@example.com');

        $this->send([
            'site_id'  => $site->id,
            'template' => EmailTemplateCatalog::TYPE_SUBSCRIBE,
            'count'    => 50,
        ])->assertStatus(422)->assertJsonValidationErrors('count');
    }

    public function test_a_run_with_nobody_eligible_is_refused_without_queueing(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('warmed@example.com', '2026-08-27 09:00:00');

        $this->send([
            'site_id'       => $site->id,
            'template'      => EmailTemplateCatalog::TYPE_SUBSCRIBE,
            'count'         => 1,
            'cooldown_days' => 7,
        ])->assertStatus(422)->assertJson(['ok' => false]);

        Mail::assertNothingSent();
        $this->assertSame(0, WarmupSend::count(), 'no run header for a run that never happened');
        // The lock must be free for the next attempt — it is taken only after the
        // audience is known to be non-empty.
        $this->assertTrue(Cache::lock(SendWarmupCampaignJob::runLockKey(), 1)->get());
    }

    public function test_the_run_records_its_own_settings(): void
    {
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('a@example.com');
        $this->addAddress('b@example.com');

        $this->send([
            'site_id'       => $site->id,
            'template'      => EmailTemplateCatalog::TYPE_SUBSCRIBE,
            'count'         => 2,
            'cooldown_days' => 30,
        ])->assertAccepted();

        $run = WarmupSend::sole();
        $this->assertSame($site->id, $run->site_id);
        $this->assertSame(2, $run->requested_count);
        $this->assertSame(30, $run->cooldown_days);
        $this->assertSame(2, $run->queued_count);
    }

    public function test_a_whole_list_run_records_no_cooldown(): void
    {
        // "Send to everyone" discards a cooldown rather than rejecting it, so the
        // header row honestly reflects that no filter was applied.
        Mail::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->addAddress('a@example.com', '2026-08-27 09:00:00');

        $this->send([
            'site_id'       => $site->id,
            'template'      => EmailTemplateCatalog::TYPE_SUBSCRIBE,
            'cooldown_days' => 30,
        ])->assertAccepted();

        $run = WarmupSend::sole();
        $this->assertNull($run->requested_count);
        $this->assertNull($run->cooldown_days);
        $this->assertSame(1, $run->queued_count, 'a recently warmed address is still contacted');
    }
}
