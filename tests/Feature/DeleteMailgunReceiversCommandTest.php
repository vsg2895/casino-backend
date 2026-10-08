<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\MailgunSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `mailgun:delete-receivers` — the permanent delete.
 *
 * Three things are worth a test here, in this order of importance:
 *
 *   1. It really is a hard delete, and it really does include rows the admin had
 *      only soft-deleted. A "permanent delete" that leaves `deleted_at` rows
 *      behind leaves exactly the rows the operator believes they just removed.
 *   2. An opt-out survives the row. `unsubscribed_at` lives on the receiver, so
 *      deleting the row would destroy the record of that person's decision —
 *      and with the unique index on `email` no longer occupied, a re-import
 *      would start mailing them again. The command carries those addresses to
 *      `mailgun_suppressions` first.
 *   3. Every filter deletes the set it names and nothing wider. This is the
 *      command where "slightly too wide" is unrecoverable.
 *
 * Dates are frozen: `--since` straddling midnight is a flake.
 */
class DeleteMailgunReceiversCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $columns  written straight to the row; none of these are fillable */
    private function receiver(string $email, array $columns = []): MailgunReceiver
    {
        $receiver = MailgunReceiver::create(['email' => $email, 'source' => MailgunReceiver::SOURCE_MANUAL]);

        if ($columns !== []) {
            DB::table('mailgun_receivers')->where('id', $receiver->id)->update($columns);
        }

        return $receiver->refresh();
    }

    private function credential(): MailgunKey
    {
        return MailgunKey::create([
            'name'            => 'Test key ' . uniqid(),
            'domain'          => 'mail.example.com',
            'api_key'         => 'key-test',
            'from_address'    => 'news@mail.example.com',
            'from_name'       => 'Test',
            'message_subject' => 'Hello',
            'message_html'    => '<p>Hi</p>',
            'batch_size'      => 100,
            'selection_order' => MailgunReceiver::ORDER_NEWEST,
            'cooldown_days'   => 7,
            'status'          => 'active',
        ]);
    }

    private function receiverCount(): int
    {
        return (int) DB::table('mailgun_receivers')->count();
    }

    // ── It is a hard delete, trashed rows included ───────────────────────────

    public function test_it_removes_every_row_including_soft_deleted_ones(): void
    {
        $this->receiver('live@example.com');
        $this->receiver('trashed@example.com', ['deleted_at' => '2026-10-01 09:00:00']);

        $this->artisan('mailgun:delete-receivers', ['--force' => true])->assertExitCode(0);

        // Not "soft-deleted": gone from the table entirely.
        $this->assertSame(0, $this->receiverCount());
    }

    public function test_trashed_only_leaves_live_receivers_alone(): void
    {
        $live    = $this->receiver('live@example.com');
        $trashed = $this->receiver('trashed@example.com', ['deleted_at' => '2026-10-01 09:00:00']);

        $this->artisan('mailgun:delete-receivers', ['--trashed' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('mailgun_receivers', ['id' => $live->id]);
        $this->assertDatabaseMissing('mailgun_receivers', ['id' => $trashed->id]);
    }

    // ── Opt-outs outlive the row ─────────────────────────────────────────────

    public function test_it_suppresses_unsubscribed_addresses_before_deleting_them(): void
    {
        $this->receiver('optedout@example.com', ['unsubscribed_at' => '2026-09-20 10:00:00']);
        $this->receiver('active@example.com');

        $this->artisan('mailgun:delete-receivers', ['--force' => true])->assertExitCode(0);

        // The opt-out survives, keyed by email — which is what
        // MailgunReceiver::scopeNotSuppressed() reads, so a re-import of this
        // address is still unmailable.
        $this->assertDatabaseHas('mailgun_suppressions', [
            'email'  => 'optedout@example.com',
            'reason' => MailgunSuppression::REASON_UNSUBSCRIBE,
        ]);

        // Someone who never opted out is not suppressed by being deleted.
        $this->assertDatabaseMissing('mailgun_suppressions', ['email' => 'active@example.com']);
    }

    public function test_forget_opt_outs_writes_no_suppression(): void
    {
        $this->receiver('optedout@example.com', ['unsubscribed_at' => '2026-09-20 10:00:00']);

        $this->artisan('mailgun:delete-receivers', ['--forget-opt-outs' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('mailgun_suppressions')->count());
    }

    public function test_it_does_not_overwrite_an_existing_suppression_reason(): void
    {
        MailgunSuppression::suppress('bounced@example.com', MailgunSuppression::REASON_BOUNCE, 'hard bounce 550');
        $this->receiver('bounced@example.com', ['unsubscribed_at' => '2026-09-20 10:00:00']);

        $this->artisan('mailgun:delete-receivers', ['--force' => true])->assertExitCode(0);

        // A recorded bounce is the stronger fact about deliverability; "the row
        // was deleted" must not hide why the address went unmailable.
        $this->assertDatabaseHas('mailgun_suppressions', [
            'email'  => 'bounced@example.com',
            'reason' => MailgunSuppression::REASON_BOUNCE,
            'detail' => 'hard bounce 550',
        ]);
        $this->assertSame(1, (int) DB::table('mailgun_suppressions')->count());
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    public function test_email_filter_is_normalised_and_deletes_only_that_address(): void
    {
        $target = $this->receiver('target@example.com');
        $other  = $this->receiver('other@example.com');

        // Mixed case and padding: the column stores the normalised form, so the
        // option has to normalise too or this matches nothing.
        $this->artisan('mailgun:delete-receivers', ['--email' => ['  Target@Example.COM '], '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('mailgun_receivers', ['id' => $target->id]);
        $this->assertDatabaseHas('mailgun_receivers', ['id' => $other->id]);
    }

    public function test_it_reports_addresses_that_are_not_on_the_list(): void
    {
        $this->receiver('known@example.com');

        $this->artisan('mailgun:delete-receivers', [
            '--email' => ['known@example.com', 'ghost@example.com'],
            '--force' => true,
        ])
            ->expectsOutputToContain('ghost@example.com')
            ->assertExitCode(0);

        $this->assertSame(0, $this->receiverCount());
    }

    public function test_unsubscribed_filter_targets_only_opt_outs(): void
    {
        $optedOut = $this->receiver('optedout@example.com', ['unsubscribed_at' => '2026-09-20 10:00:00']);
        $active   = $this->receiver('active@example.com');

        $this->artisan('mailgun:delete-receivers', ['--unsubscribed' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('mailgun_receivers', ['id' => $optedOut->id]);
        $this->assertDatabaseHas('mailgun_receivers', ['id' => $active->id]);
    }

    public function test_since_is_inclusive_from_the_start_of_the_day(): void
    {
        $before = $this->receiver('before@example.com', ['created_at' => '2026-09-30 23:59:59']);
        $onDay  = $this->receiver('onday@example.com', ['created_at' => '2026-10-01 00:00:00']);

        $this->artisan('mailgun:delete-receivers', ['--since' => '2026-10-01', '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('mailgun_receivers', ['id' => $before->id]);
        $this->assertDatabaseMissing('mailgun_receivers', ['id' => $onDay->id]);
    }

    public function test_it_rejects_a_malformed_since_date(): void
    {
        $this->receiver('keep@example.com');

        $this->artisan('mailgun:delete-receivers', ['--since' => '2026-13-01', '--force' => true])
            ->assertExitCode(2);

        $this->assertSame(1, $this->receiverCount());
    }

    // ── Safety ───────────────────────────────────────────────────────────────

    public function test_dry_run_changes_nothing(): void
    {
        $this->receiver('optedout@example.com', ['unsubscribed_at' => '2026-09-20 10:00:00']);
        $this->receiver('active@example.com');

        $this->artisan('mailgun:delete-receivers', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(2, $this->receiverCount());
        $this->assertSame(0, (int) DB::table('mailgun_suppressions')->count());
    }

    public function test_declining_the_prompt_deletes_nothing(): void
    {
        $this->receiver('keep@example.com');

        $this->artisan('mailgun:delete-receivers')
            ->expectsConfirmation('Proceed?', 'no')
            ->expectsOutputToContain('Aborted')
            ->assertExitCode(0);

        $this->assertSame(1, $this->receiverCount());
    }

    public function test_it_refuses_to_delete_non_interactively_without_force(): void
    {
        $this->receiver('keep@example.com');

        // A cron or a deploy script gets a refusal rather than a guess: there is
        // nobody to answer the prompt, and the wrong guess here is unrecoverable.
        $this->artisan('mailgun:delete-receivers', ['--no-interaction' => true])
            ->expectsOutputToContain('Refusing to delete without confirmation')
            ->assertExitCode(0);

        $this->assertSame(1, $this->receiverCount());
    }

    public function test_an_empty_list_is_not_an_error(): void
    {
        $this->artisan('mailgun:delete-receivers', ['--force' => true])
            ->expectsOutputToContain('nothing to delete')
            ->assertExitCode(0);
    }

    // ── Child rows ───────────────────────────────────────────────────────────

    public function test_it_keeps_the_send_history_and_drops_the_daily_claim(): void
    {
        $receiver   = $this->receiver('mailed@example.com');
        $credential = $this->credential();

        DB::table('mailgun_receiver_sends')->insert([
            'mailgun_key_id'      => $credential->id,
            'mailgun_receiver_id' => $receiver->id,
            'email'               => $receiver->email,
            'status'              => 'sent',
            'sent_at'             => '2026-10-06 08:00:00',
            'sent_on'             => '2026-10-06',
            'created_at'          => '2026-10-06 08:00:00',
            'updated_at'          => '2026-10-06 08:00:00',
        ]);

        DB::table('receiver_daily_claims')->insert([
            'mailgun_receiver_id' => $receiver->id,
            'claim_on'            => '2026-10-07',
            'channel'             => 'mailgun',
            'credential_id'       => $credential->id,
            'created_at'          => '2026-10-07 08:00:00',
        ]);

        $this->artisan('mailgun:delete-receivers', ['--force' => true])->assertExitCode(0);

        // SET NULL: the audit trail of what was actually delivered survives, and
        // the denormalised `email` keeps it readable.
        $this->assertDatabaseHas('mailgun_receiver_sends', [
            'email'               => 'mailed@example.com',
            'mailgun_receiver_id' => null,
        ]);

        // CASCADE: the claim existed to stop a second send to a row that no
        // longer exists, so it goes with it.
        $this->assertSame(0, (int) DB::table('receiver_daily_claims')->count());
    }
}
