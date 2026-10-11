<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\SmtpCredential;
use App\Services\ReceiverCampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * "Last sent" and "Sent" in the Receivers table, for BOTH channels.
 *
 * Reported as: a campaign run through a stored SMTP server leaves those two
 * columns untouched. They are written by {@see ReceiverCampaignSender}, which is
 * shared by the Mailgun and SMTP jobs — so either both channels update them or
 * neither does, and these tests pin that rather than leaving it to inspection.
 *
 * What they deliberately do NOT cover is whether a queue worker is running:
 * both admin endpoints answer "Run queued" and hand the work to the `low`
 * queue, so with no worker the columns stay empty with nothing wrong in the
 * code. That is the first thing to check on a server.
 */
class ReceiverSendStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // The run paces itself at 15/minute; recording the waits instead of
        // taking them keeps this test instant.
        Sleep::fake();
    }

    private function mailgunCredential(): MailgunKey
    {
        return MailgunKey::create([
            'name'            => 'Mailgun ' . uniqid(),
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

    private function smtpCredential(): SmtpCredential
    {
        return SmtpCredential::create([
            'name'            => 'Own SMTP ' . uniqid(),
            'type'            => 'smtp',
            'host'            => 'smtp.example.com',
            'port'            => 587,
            'username'        => 'mailer@example.com',
            'password'        => 'secret',
            'encryption'      => 'tls',
            'from_address'    => 'mailer@example.com',
            'from_name'       => 'Test',
            'message_subject' => 'Hello',
            'message_html'    => '<p>Hi</p>',
            'batch_size'      => 100,
            'selection_order' => MailgunReceiver::ORDER_OLDEST,
            'only_active'     => false,
            'status'          => 'active',
        ]);
    }

    private function receiver(string $email): MailgunReceiver
    {
        return MailgunReceiver::create(['email' => $email, 'source' => MailgunReceiver::SOURCE_MANUAL]);
    }

    public function test_an_smtp_run_stamps_last_sent_and_counts_the_send(): void
    {
        $receiver = $this->receiver('person@example.com');

        // Precondition, read back from the row so the column defaults apply.
        $receiver->refresh();
        $this->assertNull($receiver->last_sent_at, 'precondition: never contacted');
        $this->assertSame(0, $receiver->sent_count);

        $result = app(ReceiverCampaignSender::class)
            ->sendChunk($this->smtpCredential(), app('mailer'), [$receiver->id]);

        $this->assertSame(1, $result['sent']);

        $receiver->refresh();
        $this->assertNotNull($receiver->last_sent_at, 'the SMTP channel must stamp "Last sent"');
        $this->assertSame(1, $receiver->sent_count);
        $this->assertNull($receiver->last_error);
    }

    public function test_the_mailgun_channel_stamps_the_same_columns(): void
    {
        $receiver = $this->receiver('person@example.com');

        app(ReceiverCampaignSender::class)
            ->sendChunk($this->mailgunCredential(), app('mailer'), [$receiver->id]);

        $receiver->refresh();
        $this->assertNotNull($receiver->last_sent_at);
        $this->assertSame(1, $receiver->sent_count);
    }

    public function test_each_channel_writes_its_own_history_row(): void
    {
        // The counters are shared; the audit trail is not. A run through the
        // SMTP server must land in `smtp_receiver_sends`, not in the Mailgun log.
        $receiver = $this->receiver('person@example.com');

        app(ReceiverCampaignSender::class)
            ->sendChunk($this->smtpCredential(), app('mailer'), [$receiver->id]);

        $this->assertSame(1, (int) DB::table('smtp_receiver_sends')->where('status', 'sent')->count());
        $this->assertSame(0, (int) DB::table('mailgun_receiver_sends')->count());
    }

    public function test_a_second_run_the_same_day_does_not_re_stamp(): void
    {
        /*
         * The cross-channel daily claim: one email per address per day, whoever
         * sends it. A second run finds the claim taken and skips — so "Sent"
         * staying at 1 is the guard working, not a lost write.
         */
        $receiver = $this->receiver('person@example.com');

        app(ReceiverCampaignSender::class)
            ->sendChunk($this->smtpCredential(), app('mailer'), [$receiver->id]);

        $first = $receiver->fresh()->last_sent_at;

        $result = app(ReceiverCampaignSender::class)
            ->sendChunk($this->mailgunCredential(), app('mailer'), [$receiver->id]);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);

        $receiver->refresh();
        $this->assertSame(1, $receiver->sent_count);
        $this->assertEquals($first, $receiver->last_sent_at);
    }

    public function test_a_failed_send_records_the_error_and_leaves_last_sent_empty(): void
    {
        // What the admin sees when a server rejects the message: "Last sent"
        // stays empty — correctly, nothing was delivered — and the reason is on
        // the row rather than only in the log.
        // A mailer that rejects, passed in as the send loop's own argument —
        // no need to reach into the Mail facade, which is already faked.
        $pending = \Mockery::mock();
        $pending->shouldReceive('send')->andThrow(new \RuntimeException('550 mailbox unavailable'));
        $mailer = \Mockery::mock(\Illuminate\Contracts\Mail\Mailer::class);
        $mailer->shouldReceive('to')->andReturn($pending);

        $receiver = $this->receiver('person@example.com');

        $result = app(ReceiverCampaignSender::class)
            ->sendChunk($this->smtpCredential(), $mailer, [$receiver->id]);

        $this->assertSame(1, $result['failed']);

        $receiver->refresh();
        $this->assertNull($receiver->last_sent_at);
        $this->assertSame(0, $receiver->sent_count);
        $this->assertStringContainsString('550', (string) $receiver->last_error);
    }
}
