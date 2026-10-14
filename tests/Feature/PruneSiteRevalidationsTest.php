<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SiteRevalidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `revalidations:prune`.
 *
 * The two behaviours that make this safe to put on a schedule are the ones
 * tested hardest: failures outlive successes, and the newest attempt for every
 * site is never deleted — without that, a site with no recent content change
 * would show an empty Cache Health screen, which reads exactly like
 * revalidation having never worked.
 */
class PruneSiteRevalidationsTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-11 12:00:00');
    }

    private function attempt(Site $site, string $status, string $createdAt): SiteRevalidation
    {
        $row = SiteRevalidation::create([
            'site_id'      => $site->id,
            'tags'         => ['casinos'],
            'status'       => $status,
            'http_status'  => $status === SiteRevalidation::STATUS_SUCCESS ? 200 : 500,
            'duration_ms'  => 12,
            'triggered_by' => SiteRevalidation::TRIGGER_OBSERVER,
        ]);

        // created_at is not fillable on an immutable row, so it is written
        // directly — the whole command turns on this column.
        SiteRevalidation::query()->whereKey($row->id)->update(['created_at' => $createdAt]);

        return $row->refresh();
    }

    public function test_it_deletes_old_successes_and_keeps_recent_ones(): void
    {
        [$site] = $this->siteWithKey();

        $old = $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-08-01 10:00:00');
        $recent = $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-10-05 10:00:00');
        // Newest overall — kept whatever the window says.
        $newest = $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-10-10 10:00:00');

        $this->artisan('revalidations:prune', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('site_revalidations', ['id' => $old->id]);
        $this->assertDatabaseHas('site_revalidations', ['id' => $recent->id]);
        $this->assertDatabaseHas('site_revalidations', ['id' => $newest->id]);
    }

    public function test_failures_are_kept_three_times_longer(): void
    {
        [$site] = $this->siteWithKey();

        // Both older than the 30-day success window; only the success goes.
        $success = $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-08-01 10:00:00');
        $failure = $this->attempt($site, SiteRevalidation::STATUS_FAILED, '2026-08-01 10:00:00');
        $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-10-10 10:00:00');

        $this->artisan('revalidations:prune', ['--force' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('site_revalidations', ['id' => $success->id]);
        $this->assertDatabaseHas('site_revalidations', ['id' => $failure->id]);
    }

    public function test_the_newest_attempt_per_site_survives_any_window(): void
    {
        /*
         * A site that has had no content change in months. Its only row is
         * ancient and would fall inside every window — and deleting it would
         * make a quiet site look like a broken one.
         */
        [$quiet] = $this->siteWithKey();
        [$busy] = $this->siteWithKey();

        $only = $this->attempt($quiet, SiteRevalidation::STATUS_SUCCESS, '2026-01-01 10:00:00');
        $busyOld = $this->attempt($busy, SiteRevalidation::STATUS_SUCCESS, '2026-01-02 10:00:00');
        $busyNew = $this->attempt($busy, SiteRevalidation::STATUS_SUCCESS, '2026-10-10 10:00:00');

        $this->artisan('revalidations:prune', ['--days' => 1, '--failure-days' => 1, '--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('site_revalidations', ['id' => $only->id]);
        $this->assertDatabaseHas('site_revalidations', ['id' => $busyNew->id]);
        $this->assertDatabaseMissing('site_revalidations', ['id' => $busyOld->id]);
    }

    public function test_the_site_option_touches_only_that_site(): void
    {
        [$mine] = $this->siteWithKey();
        [$other] = $this->siteWithKey();

        $minesOld = $this->attempt($mine, SiteRevalidation::STATUS_SUCCESS, '2026-08-01 10:00:00');
        $this->attempt($mine, SiteRevalidation::STATUS_SUCCESS, '2026-10-10 10:00:00');
        $othersOld = $this->attempt($other, SiteRevalidation::STATUS_SUCCESS, '2026-08-01 10:00:00');
        $this->attempt($other, SiteRevalidation::STATUS_SUCCESS, '2026-10-10 10:00:00');

        $this->artisan('revalidations:prune', ['--site' => $mine->slug, '--force' => true])->assertExitCode(0);

        $this->assertDatabaseMissing('site_revalidations', ['id' => $minesOld->id]);
        $this->assertDatabaseHas('site_revalidations', ['id' => $othersOld->id]);
    }

    public function test_an_unknown_site_is_an_error_not_an_empty_run(): void
    {
        $this->artisan('revalidations:prune', ['--site' => 'nope', '--force' => true])
            ->expectsOutputToContain('No site with slug')
            ->assertExitCode(2);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        [$site] = $this->siteWithKey();

        $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-08-01 10:00:00');
        $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-10-10 10:00:00');

        $this->artisan('revalidations:prune', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame(2, SiteRevalidation::query()->count());
    }

    public function test_it_refuses_to_delete_non_interactively_without_force(): void
    {
        [$site] = $this->siteWithKey();

        $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-08-01 10:00:00');
        $this->attempt($site, SiteRevalidation::STATUS_SUCCESS, '2026-10-10 10:00:00');

        $this->artisan('revalidations:prune', ['--no-interaction' => true])
            ->expectsOutputToContain('Refusing to delete without confirmation')
            ->assertExitCode(0);

        $this->assertSame(2, SiteRevalidation::query()->count());
    }

    public function test_a_zero_window_is_refused(): void
    {
        // `--days=0` would mean "delete everything up to this instant", which is
        // never what someone means by a retention window.
        $this->artisan('revalidations:prune', ['--days' => 0, '--force' => true])
            ->assertExitCode(2);
    }

    public function test_an_empty_log_is_not_an_error(): void
    {
        $this->artisan('revalidations:prune', ['--force' => true])
            ->expectsOutputToContain('Nothing to prune')
            ->assertExitCode(0);
    }
}
