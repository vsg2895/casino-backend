<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\SendgridKey;
use App\Services\Mail\WarmupRateLimiter;
use App\Services\ReceiverCampaignSender;
use App\Support\Mail\SendingAccount;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

/**
 * Pacing of a receiver-campaign run — the campaign-platform rules every bulk
 * stream now shares (PacedDelivery):
 *
 *  - each sending account has its own rolling window, WARMUP_EMAILS_PER_MINUTE
 *    a minute, evenly spaced, counted across every worker;
 *  - a soft failure is retried once;
 *  - a chunk that runs out of job time hands back the receivers it did not
 *    reach instead of being killed;
 *
 * and NOTHING about who is selected or how outcomes are recorded changes.
 *
 * `Sleep::fake(syncWithCarbon: true)` records each wait and moves the clock by
 * it, so a run that would take minutes takes milliseconds.
 */
class ReceiverCampaignPacingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Carbon::setTestNow('2026-11-08 12:00:00');
        Sleep::fake(syncWithCarbon: true);
        // 8 a minute, spaced window / max = 7.5 s apart.
        config([
            'warmup.rate_limit.max_emails'           => 8,
            'warmup.rate_limit.window_seconds'       => 60,
            'warmup.rate_limit.min_interval_seconds' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function credential(): MailgunKey
    {
        return MailgunKey::create([
            'name'            => 'Pacing ' . uniqid(),
            'domain'          => 'mail.example.com',
            'api_key'         => 'key-test',
            'from_address'    => 'news@mail.example.com',
            'from_name'       => 'Test',
            'message_subject' => 'Hello',
            'message_html'    => '<p>Hi</p>',
            'batch_size'      => 100,
            'selection_order' => MailgunReceiver::ORDER_OLDEST,
            'status'          => 'active',
        ]);
    }

    /** The real mailer, with Mail::fake() underneath — nothing leaves. */
    private function mailer(): Mailer
    {
        return app('mailer');
    }

    /** @return list<int> */
    private function receiverIds(int $count, string $prefix = 'person'): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = MailgunReceiver::create([
                'email'  => "{$prefix}{$i}@example.com",
                'source' => MailgunReceiver::SOURCE_MANUAL,
            ])->id;
        }

        return $ids;
    }

    public function test_messages_are_spaced_evenly_across_the_minute(): void
    {
        $result = app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $this->mailer(), $this->receiverIds(3));

        $this->assertSame(3, $result['sent']);
        // The first goes at once; each later one waits its 7.5 s share.
        Sleep::assertSequence([Sleep::usleep(7_500_000), Sleep::usleep(7_500_000)]);
    }

    public function test_no_sixty_seconds_ever_hold_more_than_the_configured_rate(): void
    {
        // Spacing off, so only the ceiling shapes the run: 8 go at once, then
        // the ninth must wait until the first leaves the window.
        config(['warmup.rate_limit.min_interval_seconds' => 0]);
        $startedAt = Carbon::now()->copy();

        $result = app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $this->mailer(), $this->receiverIds(20), 3600);

        $this->assertSame(20, $result['sent']);
        // 1–8 at 0 s, 9–16 at 60 s, 17–20 at 120 s: never more than 8 in a minute.
        $this->assertSame(120, (int) $startedAt->diffInSeconds(Carbon::now()));
    }

    public function test_each_sending_account_has_its_own_window(): void
    {
        $sender = app(ReceiverCampaignSender::class);

        $sender->sendChunk($this->credential(), $this->mailer(), $this->receiverIds(1, 'a'));
        // A different credential is a different account: it does not wait for
        // the first one's window.
        $sender->sendChunk($this->credential(), $this->mailer(), $this->receiverIds(1, 'b'));

        Sleep::assertNeverSlept();

        $limiter = app(WarmupRateLimiter::class);
        $this->assertNotSame(
            $limiter->forAccount('mailgun:1')->key(),
            $limiter->forAccount('mailgun:2')->key(),
        );
    }

    public function test_a_key_used_by_a_schedule_and_by_a_receiver_campaign_is_one_account(): void
    {
        $key = SendgridKey::create(['name' => 'K', 'api_key' => 'SG.test-key-value-long-enough', 'status' => 'active']);

        $this->assertSame(
            SendingAccount::forProvider('sendgrid', $key->id),
            SendingAccount::forReceiverCredential($key),
        );
        // And the .env SMTP mailbox a schedule uses IS warmup's window when the
        // two mailers are the same mailbox.
        config(['mail.admin_mailer' => 'smtp', 'warmup.mailer' => 'smtp']);
        $this->assertSame(
            WarmupRateLimiter::WINDOW_KEY,
            WarmupRateLimiter::keyForAccount(SendingAccount::forProvider('smtp', null)),
        );
    }

    public function test_a_chunk_out_of_time_hands_back_the_receivers_it_did_not_reach(): void
    {
        $ids = $this->receiverIds(4);

        // An 81 s job keeps one second of budget after the 60 s window and the
        // 20 s margin: the first send goes at once, the second after its 7.5 s gap,
        // and by then the budget is spent.
        $result = app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $this->mailer(), $ids, 81);

        $this->assertSame(2, $result['sent']);
        $this->assertSame([$ids[2], $ids[3]], $result['deferred']);
        Mail::assertSentCount(2);
        // Deferred receivers took no claim, so the follow-up batch can mail them today.
        $this->assertSame(2, DB::table('receiver_daily_claims')->count());
    }

    public function test_a_soft_failure_is_retried_once_and_a_hard_one_is_not(): void
    {
        $attempts = 0;
        $mailer = \Mockery::mock(Mailer::class);
        $pending = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
        $mailer->shouldReceive('to')->andReturn($pending);
        $pending->shouldReceive('send')->andReturnUsing(function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('Expected response code "250" but got code "421", with message "421 Try again later".');
            }

            if ($attempts === 3) {
                throw new RuntimeException('550 No such user');
            }

            return null;
        });

        $result = app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $mailer, $this->receiverIds(2));

        // #1: 421, retried, delivered. #2: 550 — permanent, not retried.
        $this->assertSame(3, $attempts);
        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['suppressed']);
    }

    public function test_pacing_does_not_change_what_is_sent(): void
    {
        $result = app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $this->mailer(), $this->receiverIds(4));

        $this->assertSame(
            ['requested' => 4, 'eligible' => 4, 'sent' => 4, 'failed' => 0, 'skipped' => 0, 'suppressed' => 0, 'deferred' => []],
            collect($result)->except('duration_ms')->all(),
        );
        Mail::assertSentCount(4);
    }
}
