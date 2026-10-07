<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Services\MailgunReceiverSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WHO a campaign run selects — the cooldown above all.
 *
 * The question these tests answer is the one that was raised about the feature:
 * does `Cooldown (days)` pick the people who HAVE been mailed recently, or the
 * people who have NOT? It must be the second — "skip anyone mailed within N
 * days" — and every path (the modal's count, the batch preview and the sending
 * job) has to agree, because they all enter through
 * {@see MailgunReceiverSelector::selection()}.
 *
 * Dates are frozen: a cooldown test that straddles midnight is a flake.
 */
class MailgunReceiverSelectionTest extends TestCase
{
    use RefreshDatabase;

    private MailgunReceiverSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->selector = app(MailgunReceiverSelector::class);
    }

    private function credential(?int $cooldownDays, string $order = MailgunReceiver::ORDER_OLDEST, int $batch = 100): MailgunKey
    {
        return MailgunKey::create([
            'name'            => 'Test key ' . uniqid(),
            'domain'          => 'mail.example.com',
            'api_key'         => 'key-test',
            'from_address'    => 'news@mail.example.com',
            'from_name'       => 'Test',
            'message_subject' => 'Hello',
            'message_html'    => '<p>Hi</p>',
            'batch_size'      => $batch,
            'selection_order' => $order,
            'cooldown_days'   => $cooldownDays,
            'status'          => 'active',
        ]);
    }

    private function receiver(string $email, ?string $lastSentAt = null, ?string $createdAt = null): MailgunReceiver
    {
        $receiver = MailgunReceiver::create(['email' => $email, 'source' => MailgunReceiver::SOURCE_MANUAL]);

        // Written straight to the row: neither column is fillable, and both are
        // exactly what the selection reads.
        $columns = [];

        if ($lastSentAt !== null) {
            $columns['last_sent_at'] = $lastSentAt;
        }

        if ($createdAt !== null) {
            $columns['created_at'] = $createdAt;
        }

        if ($columns !== []) {
            DB::table('mailgun_receivers')->where('id', $receiver->id)->update($columns);
        }

        return $receiver->refresh();
    }

    /** @return list<string> Selected addresses, in selection order. */
    private function selected(MailgunKey $credential): array
    {
        return $this->selector->selection($credential)
            ->inSelectionOrder($credential->campaignSelectionOrder())
            ->pluck('email')
            ->all();
    }

    public function test_the_cooldown_keeps_the_people_who_have_not_been_mailed(): void
    {
        // The exact question raised: with a 1-day cooldown, who is selected?
        $this->receiver('never-mailed@example.com');
        $this->receiver('mailed-an-hour-ago@example.com', '2026-10-07 11:00:00');
        $this->receiver('mailed-three-days-ago@example.com', '2026-10-04 12:00:00');

        $selected = $this->selected($this->credential(1));
        sort($selected);

        // Mailed an hour ago → inside the window → EXCLUDED.
        $this->assertSame(
            ['mailed-three-days-ago@example.com', 'never-mailed@example.com'],
            $selected,
        );
    }

    public function test_a_never_mailed_receiver_always_qualifies(): void
    {
        // The load-bearing NULL branch: `last_sent_at <= cutoff` is UNKNOWN for
        // NULL, so without the explicit IS NULL the people who have never been
        // contacted — the whole point of the list — would be dropped.
        $this->receiver('fresh@example.com');

        $this->assertSame(['fresh@example.com'], $this->selected($this->credential(30)));
    }

    public function test_the_window_is_counted_in_days_from_now(): void
    {
        $this->receiver('just-outside@example.com', '2026-10-04 11:59:00'); // 3d + 1min ago
        $this->receiver('just-inside@example.com', '2026-10-04 12:01:00');  // 2d 23h 59m ago

        $this->assertSame(['just-outside@example.com'], $this->selected($this->credential(3)));
    }

    public function test_no_cooldown_selects_everyone_sendable(): void
    {
        $this->receiver('a@example.com', '2026-10-07 11:59:00');
        $this->receiver('b@example.com');

        foreach ([null, 0] as $noCooldown) {
            $selected = $this->selected($this->credential($noCooldown));
            sort($selected);
            $this->assertSame(['a@example.com', 'b@example.com'], $selected, 'cooldown: ' . var_export($noCooldown, true));
        }
    }

    public function test_unsubscribed_and_suppressed_are_never_selected(): void
    {
        $this->receiver('wanted@example.com');

        $gone = $this->receiver('unsubscribed@example.com');
        DB::table('mailgun_receivers')->where('id', $gone->id)->update(['unsubscribed_at' => now()]);

        $this->receiver('suppressed@example.com');
        DB::table('mailgun_suppressions')->insert([
            'email'      => 'suppressed@example.com',
            'reason'     => 'bounce',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(['wanted@example.com'], $this->selected($this->credential(1)));
    }

    public function test_someone_already_mailed_today_is_not_selected_again(): void
    {
        $claimed = $this->receiver('claimed@example.com');
        $this->receiver('free@example.com');

        DB::table('receiver_daily_claims')->insert([
            'mailgun_receiver_id' => $claimed->id,
            'claim_on'            => now()->toDateString(),
            // Recorded for diagnosis only; nothing reads them back, but both
            // are NOT NULL.
            'channel'             => 'mailgun',
            'credential_id'       => 1,
            'created_at'          => now(),
        ]);

        $this->assertSame(['free@example.com'], $this->selected($this->credential(null)));
    }

    public function test_oldest_added_first_orders_by_creation(): void
    {
        $this->receiver('second@example.com', null, '2026-09-02 10:00:00');
        $this->receiver('first@example.com', null, '2026-09-01 10:00:00');
        $this->receiver('third@example.com', null, '2026-09-03 10:00:00');

        $this->assertSame(
            ['first@example.com', 'second@example.com', 'third@example.com'],
            $this->selected($this->credential(1, MailgunReceiver::ORDER_OLDEST)),
        );
    }

    public function test_newest_added_first_reverses_it(): void
    {
        $this->receiver('second@example.com', null, '2026-09-02 10:00:00');
        $this->receiver('first@example.com', null, '2026-09-01 10:00:00');
        $this->receiver('third@example.com', null, '2026-09-03 10:00:00');

        $this->assertSame(
            ['third@example.com', 'second@example.com', 'first@example.com'],
            $this->selected($this->credential(1, MailgunReceiver::ORDER_NEWEST)),
        );
    }

    public function test_the_count_the_modal_shows_matches_the_rows_selected(): void
    {
        $this->receiver('a@example.com');
        $this->receiver('b@example.com');
        $this->receiver('recent@example.com', '2026-10-07 10:00:00');

        $credential = $this->credential(1);

        $this->assertSame(2, $this->selector->eligible($credential));
        $this->assertCount(2, $this->selected($credential));
    }
}
