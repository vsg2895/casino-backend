<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Services\ReceiverCampaignSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Pacing of a receiver-campaign run.
 *
 * The requirement: no more than fifteen messages a minute, achieved by waiting
 * between them — and NOTHING else about the send changed. So these tests assert
 * both halves: that the waits happen, and that every message still goes out.
 *
 * `Sleep::fake()` records the waits instead of taking them; without it a
 * three-message test would sit here for eight seconds.
 */
class ReceiverCampaignPacingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Sleep::fake();
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
    private function mailer(): \Illuminate\Contracts\Mail\Mailer
    {
        return app('mailer');
    }

    /** @return list<int> */
    private function receiverIds(int $count): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $ids[] = MailgunReceiver::create([
                'email'  => "person{$i}@example.com",
                'source' => MailgunReceiver::SOURCE_MANUAL,
            ])->id;
        }

        return $ids;
    }

    public function test_fifteen_a_minute_means_four_seconds_between_messages(): void
    {
        config(['newsletters.receiver_campaign_per_minute' => 15]);

        $result = app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $this->mailer(), $this->receiverIds(3));

        $this->assertSame(3, $result['sent']);
        // Three sends, three waits of 60/15 — the pace holds whether or not the
        // last one is strictly needed, and a run is queued so it costs nothing.
        Sleep::assertSleptTimes(3);
        Sleep::assertSequence([Sleep::for(4)->seconds(), Sleep::for(4)->seconds(), Sleep::for(4)->seconds()]);
    }

    public function test_the_rate_is_configurable(): void
    {
        config(['newsletters.receiver_campaign_per_minute' => 30]);

        app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $this->mailer(), $this->receiverIds(2));

        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(2)->seconds()]);
    }

    public function test_zero_disables_the_wait(): void
    {
        config(['newsletters.receiver_campaign_per_minute' => 0]);

        $result = app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $this->mailer(), $this->receiverIds(2));

        $this->assertSame(2, $result['sent']);
        Sleep::assertNeverSlept();
    }

    public function test_pacing_does_not_change_what_is_sent(): void
    {
        config(['newsletters.receiver_campaign_per_minute' => 15]);

        $ids = $this->receiverIds(4);
        $result = app(ReceiverCampaignSender::class)->sendChunk($this->credential(), $this->mailer(), $ids);

        // Every selected receiver still gets exactly one message, and the
        // counters report the same numbers they did before pacing existed.
        $this->assertSame(
            ['requested' => 4, 'eligible' => 4, 'sent' => 4, 'failed' => 0, 'skipped' => 0, 'suppressed' => 0],
            collect($result)->except('duration_ms')->all(),
        );
        Mail::assertSentCount(4);
    }

    public function test_a_skipped_receiver_does_not_wait(): void
    {
        config(['newsletters.receiver_campaign_per_minute' => 15]);
        $credential = $this->credential();
        $ids = $this->receiverIds(2);

        // Mail the first one, then run again: it is claimed for today, so the
        // second run sends ONE message and must wait only for that one.
        app(ReceiverCampaignSender::class)->sendChunk($credential, $this->mailer(), [$ids[0]]);
        Sleep::fake();

        $result = app(ReceiverCampaignSender::class)->sendChunk($credential, $this->mailer(), $ids);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Sleep::assertSleptTimes(1);
    }
}
