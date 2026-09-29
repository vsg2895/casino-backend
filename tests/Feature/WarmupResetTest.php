<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWarmupCampaignJob;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `warmup:reset` — putting the warmup section back to its pre-first-run state.
 *
 * The invariant every test here defends: the RECEIVER LIST SURVIVES. This
 * command resets what the platform remembers about the addresses, never who is
 * on the list, and a regression in that direction would silently destroy an
 * imported seed list that took real effort to assemble.
 */
class WarmupResetTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function address(string $email, ?string $lastSentAt = null): WarmupEmail
    {
        $row = WarmupEmail::create(['email' => $email]);
        $row->forceFill(['last_sent_at' => $lastSentAt === null ? null : Carbon::parse($lastSentAt)])->save();

        return $row->refresh();
    }

    private function history(int $rows = 3): WarmupSend
    {
        $send = WarmupSend::create([
            'site_id'         => null,
            'user_id'         => null,
            'template'        => EmailTemplateCatalog::TYPE_PROMOTION,
            'requested_count' => null,
            'cooldown_days'   => null,
        ]);

        for ($i = 1; $i <= $rows; $i++) {
            WarmupSendRecipient::create([
                'warmup_send_id'  => $send->id,
                'warmup_email_id' => null,
                'site_id'         => null,
                'email'           => "seed{$i}@example.com",
                'template'        => EmailTemplateCatalog::TYPE_PROMOTION,
                'status'          => WarmupSendRecipient::STATUS_SENT,
                'sent_at'         => Carbon::now(),
            ]);
        }

        return $send;
    }

    public function test_it_clears_last_sent_and_the_history_but_keeps_every_address(): void
    {
        $this->address('kept1@example.com', '2026-09-01 10:00:00');
        $this->address('kept2@example.com', '2026-09-02 10:00:00');
        $this->address('never@example.com');
        $this->history(3);

        $this->artisan('warmup:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(3, WarmupEmail::count(), 'the receiver list must survive a reset');
        $this->assertSame(0, WarmupEmail::query()->whereNotNull('last_sent_at')->count());
        $this->assertSame(0, WarmupSendRecipient::count());
        $this->assertSame(0, WarmupSend::count());
    }

    public function test_last_sent_only_leaves_the_history_alone(): void
    {
        $this->address('seed@example.com', '2026-09-01 10:00:00');
        $this->history(2);

        $this->artisan('warmup:reset', ['--last-sent' => true, '--force' => true])->assertSuccessful();

        $this->assertNull(WarmupEmail::sole()->last_sent_at);
        $this->assertSame(2, WarmupSendRecipient::count(), 'the history was not asked for');
        $this->assertSame(1, WarmupSend::count());
    }

    public function test_history_only_leaves_last_sent_alone(): void
    {
        $this->address('seed@example.com', '2026-09-01 10:00:00');
        $this->history(2);

        $this->artisan('warmup:reset', ['--history' => true, '--force' => true])->assertSuccessful();

        $this->assertNotNull(WarmupEmail::sole()->last_sent_at, 'the cooldown stamp was not asked for');
        $this->assertSame(0, WarmupSendRecipient::count());
        $this->assertSame(0, WarmupSend::count());
    }

    public function test_it_refuses_while_a_warmup_run_holds_the_lock(): void
    {
        // Wiping the history under a running fan-out would leave the run
        // half-recorded and `last_sent_at` stamped for addresses whose history
        // row had just been deleted.
        $this->address('seed@example.com', '2026-09-01 10:00:00');
        $this->history(2);

        $lock = Cache::lock(SendWarmupCampaignJob::runLockKey(), 60);
        $this->assertTrue($lock->get(), 'the test needs the lock to be held');

        try {
            $this->artisan('warmup:reset', ['--force' => true])->assertFailed();

            $this->assertNotNull(WarmupEmail::sole()->last_sent_at, 'nothing may change while a run is active');
            $this->assertSame(2, WarmupSendRecipient::count());
        } finally {
            $lock->release();
        }
    }

    public function test_declining_the_confirmation_changes_nothing(): void
    {
        $this->address('seed@example.com', '2026-09-01 10:00:00');
        $this->history(2);

        $this->artisan('warmup:reset')
            ->expectsConfirmation('Proceed?', 'no')
            ->assertSuccessful();

        $this->assertNotNull(WarmupEmail::sole()->last_sent_at, 'an unconfirmed run must change nothing');
        $this->assertSame(2, WarmupSendRecipient::count());
    }

    public function test_the_lock_is_released_so_a_run_can_start_afterwards(): void
    {
        // The reset holds the run lock while it works. Leaving it held would
        // block every future warmup run until the TTL expired.
        $this->address('seed@example.com', '2026-09-01 10:00:00');

        $this->artisan('warmup:reset', ['--force' => true])->assertSuccessful();

        $lock = Cache::lock(SendWarmupCampaignJob::runLockKey(), 60);
        $this->assertTrue($lock->get(), 'the lock must be free once the reset has finished');
        $lock->release();
    }

    public function test_it_reports_when_there_is_nothing_to_reset(): void
    {
        $this->address('seed@example.com');

        $this->artisan('warmup:reset', ['--force' => true])
            ->expectsOutputToContain('Nothing to reset')
            ->assertSuccessful();

        $this->assertSame(1, WarmupEmail::count());
    }
}
