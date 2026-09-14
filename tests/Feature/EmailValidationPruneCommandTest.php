<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailValidationLog;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `email-validation:prune`.
 *
 * These rows hold visitor email addresses, so retention is an obligation rather
 * than housekeeping — a prune that silently does nothing means the project keeps
 * personal data indefinitely without anyone noticing.
 */
class EmailValidationPruneCommandTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function logAged(Site $site, int $monthsAgo, string $email): EmailValidationLog
    {
        $at = Carbon::now('UTC')->subMonths($monthsAgo);

        $log = EmailValidationLog::create([
            'site_id' => $site->id, 'email' => $email, 'source' => 'subscribe_x',
            'outcome' => 'allowed', 'verdict' => 'Valid', 'score' => 0.9,
            'was_cached' => false, 'quota_month' => $at->format('Y-m'),
        ]);

        // created_at is managed by Eloquent, so it has to be forced afterwards.
        $log->forceFill(['created_at' => $at])->save();

        return $log;
    }

    public function test_it_deletes_rows_past_the_retention_window(): void
    {
        [$site] = $this->siteWithKey();
        $old = $this->logAged($site, 18, 'old@example.com');
        $recent = $this->logAged($site, 2, 'recent@example.com');

        $this->artisan('email-validation:prune')->assertSuccessful();

        $this->assertDatabaseMissing('email_validation_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('email_validation_logs', ['id' => $recent->id]);
    }

    public function test_the_window_is_configurable(): void
    {
        [$site] = $this->siteWithKey();
        $this->logAged($site, 4, 'four-months@example.com');

        // Default 12 months keeps it…
        $this->artisan('email-validation:prune')->assertSuccessful();
        $this->assertSame(1, EmailValidationLog::count());

        // …a 3-month window does not.
        $this->artisan('email-validation:prune', ['--months' => 3])->assertSuccessful();
        $this->assertSame(0, EmailValidationLog::count());
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        [$site] = $this->siteWithKey();
        $this->logAged($site, 24, 'ancient@example.com');

        $this->artisan('email-validation:prune', ['--dry-run' => true])->assertSuccessful();

        // The whole point of a dry run on a destructive command.
        $this->assertSame(1, EmailValidationLog::count());
    }

    public function test_a_zero_or_negative_window_is_refused(): void
    {
        [$site] = $this->siteWithKey();
        $this->logAged($site, 24, 'ancient@example.com');

        // "--months=0" would mean "delete everything", which is never what an
        // operator meant to type.
        $this->artisan('email-validation:prune', ['--months' => 0])->assertFailed();
        $this->assertSame(1, EmailValidationLog::count());
    }

    public function test_it_succeeds_when_there_is_nothing_to_prune(): void
    {
        $this->artisan('email-validation:prune')->assertSuccessful();
    }

    public function test_it_is_registered_on_the_schedule(): void
    {
        // A prune that is never invoked is the same as no prune at all.
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->map(fn ($e) => (string) $e->command);

        $this->assertTrue(
            $commands->contains(fn (string $c): bool => str_contains($c, 'email-validation:prune')),
            'email-validation:prune must be scheduled',
        );
    }
}
