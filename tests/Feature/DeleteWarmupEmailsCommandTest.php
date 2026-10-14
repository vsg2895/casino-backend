<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWarmupCampaignJob;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `warmup:delete-emails`.
 *
 * Three things are worth pinning, in this order:
 *
 *   1. It REFUSES while a warmup run holds the lock. A run dispatches batches
 *      carrying address ids and then sends through them for minutes; deleting
 *      underneath it is the one genuinely destructive thing this command could
 *      do.
 *   2. The send history survives. `warmup_email_id` is SET NULL and the row
 *      keeps its `email`, so the record of what the sending mailbox actually
 *      did outlives any tidying of the list.
 *   3. Each filter deletes the set it names and nothing wider — these rows do
 *      not soft delete, so "slightly too wide" is unrecoverable.
 */
class DeleteWarmupEmailsCommandTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 12:00:00');
    }

    /** @param  array<string, mixed>  $columns  written straight to the row */
    private function address(string $email, array $columns = []): WarmupEmail
    {
        $row = WarmupEmail::create(['email' => $email]);

        if ($columns !== []) {
            DB::table('warmup_emails')->where('id', $row->id)->update($columns);
        }

        return $row->refresh();
    }

    private function total(): int
    {
        return (int) DB::table('warmup_emails')->count();
    }

    // ── the run lock ─────────────────────────────────────────────────────────

    public function test_it_refuses_while_a_warmup_run_holds_the_lock(): void
    {
        $this->address('seed@example.com');

        $lock = Cache::lock(SendWarmupCampaignJob::runLockKey(), 60);
        $this->assertTrue($lock->get(), 'precondition: the test holds the run lock');

        try {
            $this->artisan('warmup:delete-emails', ['--force' => true])
                ->expectsOutputToContain('A warmup run is in progress')
                ->assertExitCode(1);

            $this->assertSame(1, $this->total(), 'nothing may be deleted under a running fan-out');
        } finally {
            $lock->release();
        }
    }

    public function test_it_releases_the_lock_so_a_run_can_follow(): void
    {
        $this->address('seed@example.com');

        $this->artisan('warmup:delete-emails', ['--force' => true])->assertExitCode(0);

        $lock = Cache::lock(SendWarmupCampaignJob::runLockKey(), 60);
        $this->assertTrue($lock->get(), 'the command must not leave the lock held');
        $lock->release();
    }

    // ── history outlives the address ─────────────────────────────────────────

    public function test_the_send_history_survives_the_address(): void
    {
        [$site] = $this->siteWithKey();
        $address = $this->address('seed@example.com');

        $run = WarmupSend::create([
            'site_id'      => $site->id,
            'template'     => 'promotion',
            'queued_count' => 1,
            'status'       => 'completed',
        ]);

        WarmupSendRecipient::create([
            'warmup_send_id'  => $run->id,
            'warmup_email_id' => $address->id,
            'site_id'         => $site->id,
            'email'           => $address->email,
            'template'        => 'promotion',
            'status'          => 'sent',
            'sent_at'         => Carbon::now(),
        ]);

        $this->artisan('warmup:delete-emails', ['--force' => true])->assertExitCode(0);

        $this->assertSame(0, $this->total());
        // SET NULL, and the denormalised address keeps the row readable.
        $this->assertDatabaseHas('warmup_send_recipients', [
            'email'           => 'seed@example.com',
            'warmup_email_id' => null,
            'status'          => 'sent',
        ]);
        $this->assertDatabaseHas('warmup_sends', ['id' => $run->id]);
    }

    // ── filters ──────────────────────────────────────────────────────────────

    public function test_email_filter_is_normalised_and_deletes_only_that_address(): void
    {
        $target = $this->address('target@example.com');
        $other = $this->address('other@example.com');

        $this->artisan('warmup:delete-emails', ['--email' => ['  Target@Example.COM '], '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('warmup_emails', ['id' => $target->id]);
        $this->assertDatabaseHas('warmup_emails', ['id' => $other->id]);
    }

    public function test_it_reports_addresses_that_are_not_on_the_list(): void
    {
        $this->address('known@example.com');

        $this->artisan('warmup:delete-emails', [
            '--email' => ['known@example.com', 'ghost@example.com'],
            '--force' => true,
        ])
            ->expectsOutputToContain('ghost@example.com')
            ->assertExitCode(0);

        $this->assertSame(0, $this->total());
    }

    public function test_never_sent_spares_every_address_that_has_been_warmed(): void
    {
        $fresh = $this->address('fresh@example.com');
        $warmed = $this->address('warmed@example.com', ['last_sent_at' => '2026-10-01 09:00:00']);

        $this->artisan('warmup:delete-emails', ['--never-sent' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('warmup_emails', ['id' => $fresh->id]);
        $this->assertDatabaseHas('warmup_emails', ['id' => $warmed->id]);
    }

    public function test_since_is_inclusive_from_the_start_of_the_day(): void
    {
        $before = $this->address('before@example.com', ['created_at' => '2026-09-30 23:59:59']);
        $onDay = $this->address('onday@example.com', ['created_at' => '2026-10-01 00:00:00']);

        $this->artisan('warmup:delete-emails', ['--since' => '2026-10-01', '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('warmup_emails', ['id' => $before->id]);
        $this->assertDatabaseMissing('warmup_emails', ['id' => $onDay->id]);
    }

    public function test_it_rejects_a_malformed_since_date(): void
    {
        $this->address('keep@example.com');

        $this->artisan('warmup:delete-emails', ['--since' => '2026-13-01', '--force' => true])
            ->assertExitCode(2);

        $this->assertSame(1, $this->total());
    }

    // ── safety ───────────────────────────────────────────────────────────────

    public function test_dry_run_changes_nothing(): void
    {
        $this->address('a@example.com');
        $this->address('b@example.com', ['last_sent_at' => '2026-10-01 09:00:00']);

        $this->artisan('warmup:delete-emails', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(2, $this->total());
    }

    public function test_declining_the_prompt_deletes_nothing(): void
    {
        $this->address('keep@example.com');

        $this->artisan('warmup:delete-emails')
            ->expectsConfirmation('Proceed?', 'no')
            ->expectsOutputToContain('Aborted')
            ->assertExitCode(0);

        $this->assertSame(1, $this->total());
    }

    public function test_it_refuses_to_delete_non_interactively_without_force(): void
    {
        $this->address('keep@example.com');

        $this->artisan('warmup:delete-emails', ['--no-interaction' => true])
            ->expectsOutputToContain('Refusing to delete without confirmation')
            ->assertExitCode(0);

        $this->assertSame(1, $this->total());
    }

    public function test_an_empty_list_is_not_an_error(): void
    {
        $this->artisan('warmup:delete-emails', ['--force' => true])
            ->expectsOutputToContain('nothing to delete')
            ->assertExitCode(0);
    }
}
