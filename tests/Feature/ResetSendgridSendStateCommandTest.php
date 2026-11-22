<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Command;
use App\Models\EmailSchedule;
use App\Models\ScheduleSendHistory;
use App\Models\SendgridReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `sendgrid:reset`.
 *
 * It exists because the Email History → Schedules tab had nothing that could
 * clear it: `histories:clear` covers six streams and `schedule_send_histories`
 * is not one of them.
 *
 * Three things are worth pinning, in this order:
 *
 *   1. It clears BOTH halves by default — the receivers' "Last sent" and the
 *      schedule history. They feed two different cooldowns (the receiver list's
 *      and the schedule's "Skip addresses contacted in the last N days"), and
 *      clearing one alone leaves an address eligible by one rule and resting by
 *      the other, which is the state that makes a run look broken.
 *   2. It deletes the set it names and nothing wider: SendGrid rows only, and
 *      with --site, one site's only. These rows do not soft delete.
 *   3. It never touches the receiver list itself. "Reset what we remember about
 *      sending" must not become a way to lose an imported list.
 */
class ResetSendgridSendStateCommandTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-21 12:00:00');
    }

    private function history(int $siteId, string $provider, string $email): ScheduleSendHistory
    {
        return ScheduleSendHistory::create([
            'email_schedule_id' => null,
            'schedule_name'     => 'Nightly',
            'site_id'           => $siteId,
            'audience'          => EmailSchedule::AUDIENCE_SENDGRID_RECEIVERS,
            'recipient_id'      => null,
            'email'             => $email,
            'template'          => EmailSchedule::TEMPLATE_SUBSCRIBE,
            'provider'          => $provider,
            'credential_id'     => 1,
            'credential_label'  => 'A key',
            'from_address'      => 'a@b.test',
            'status'            => ScheduleSendHistory::STATUS_SENT,
            'sent_at'           => now(),
            'sent_date'         => now()->toDateString(),
        ]);
    }

    /**
     * `last_sent_at` and `sent_count` are deliberately NOT fillable — only a
     * real send writes them — so they are forced here rather than mass
     * assigned. Without that the "it cleared them" assertions would pass on a
     * row where they were never set.
     */
    private function receiver(string $email): SendgridReceiver
    {
        $receiver = SendgridReceiver::create(['email' => $email]);
        $receiver->forceFill(['last_sent_at' => now()->subDay(), 'sent_count' => 4])->save();

        return $receiver;
    }

    public function test_it_clears_both_the_last_sent_counters_and_the_sendgrid_history(): void
    {
        [$site] = $this->siteWithKey();
        $this->receiver('one@example.com');
        $this->history($site->id, EmailSchedule::PROVIDER_SENDGRID, 'one@example.com');
        $this->history($site->id, EmailSchedule::PROVIDER_SENDGRID_ENV, 'two@example.com');

        $this->artisan('sendgrid:reset --force')->assertSuccessful();

        $this->assertSame(0, ScheduleSendHistory::count());
        $receiver = SendgridReceiver::firstWhere('email', 'one@example.com');
        $this->assertNull($receiver?->last_sent_at);
        $this->assertSame(0, (int) $receiver?->sent_count);
    }

    public function test_it_leaves_other_providers_history_alone(): void
    {
        [$site] = $this->siteWithKey();
        $this->history($site->id, EmailSchedule::PROVIDER_SENDGRID, 'sendgrid@example.com');
        $this->history($site->id, EmailSchedule::PROVIDER_MAILGUN, 'mailgun@example.com');
        $this->history($site->id, EmailSchedule::PROVIDER_SMTP, 'smtp@example.com');

        $this->artisan('sendgrid:reset --history --force')->assertSuccessful();

        $this->assertSame(
            ['mailgun@example.com', 'smtp@example.com'],
            ScheduleSendHistory::orderBy('email')->pluck('email')->all(),
        );
    }

    public function test_the_site_option_narrows_the_history_to_one_site(): void
    {
        [$site] = $this->siteWithKey();
        [$other] = $this->siteWithKey();
        $this->history($site->id, EmailSchedule::PROVIDER_SENDGRID, 'mine@example.com');
        $this->history($other->id, EmailSchedule::PROVIDER_SENDGRID, 'theirs@example.com');

        $this->artisan("sendgrid:reset --history --site={$site->slug} --force")->assertSuccessful();

        $this->assertSame(['theirs@example.com'], ScheduleSendHistory::pluck('email')->all());
    }

    public function test_an_unknown_site_is_refused_rather_than_treated_as_every_site(): void
    {
        [$site] = $this->siteWithKey();
        $this->history($site->id, EmailSchedule::PROVIDER_SENDGRID, 'mine@example.com');

        $this->artisan('sendgrid:reset --history --site=no-such-site --force')
            ->assertExitCode(Command::INVALID);

        $this->assertSame(1, ScheduleSendHistory::count());
    }

    public function test_the_flags_separate_the_two_halves(): void
    {
        [$site] = $this->siteWithKey();
        $this->receiver('one@example.com');
        $this->history($site->id, EmailSchedule::PROVIDER_SENDGRID, 'one@example.com');

        $this->artisan('sendgrid:reset --last-sent --force')->assertSuccessful();
        $this->assertNull(SendgridReceiver::firstWhere('email', 'one@example.com')?->last_sent_at);
        $this->assertSame(1, ScheduleSendHistory::count(), 'the history must survive --last-sent');

        $this->artisan('sendgrid:reset --history --force')->assertSuccessful();
        $this->assertSame(0, ScheduleSendHistory::count());
    }

    public function test_a_dry_run_reports_without_changing_anything(): void
    {
        [$site] = $this->siteWithKey();
        $this->receiver('one@example.com');
        $this->history($site->id, EmailSchedule::PROVIDER_SENDGRID, 'one@example.com');

        $this->artisan('sendgrid:reset --dry-run')->assertSuccessful();

        $this->assertSame(1, ScheduleSendHistory::count());
        $this->assertNotNull(SendgridReceiver::firstWhere('email', 'one@example.com')?->last_sent_at);
    }

    public function test_it_never_removes_a_receiver(): void
    {
        [$site] = $this->siteWithKey();
        $this->receiver('keep@example.com');
        $this->history($site->id, EmailSchedule::PROVIDER_SENDGRID, 'keep@example.com');

        $this->artisan('sendgrid:reset --force')->assertSuccessful();

        $this->assertSame(1, SendgridReceiver::withTrashed()->count());
    }
}
