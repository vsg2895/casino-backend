<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Models\SiteRevalidation;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Trims the revalidation log — the history behind the admin's Cache Health
 * screen.
 *
 *   php artisan revalidations:prune                      → successes older than 30 days
 *   php artisan revalidations:prune --days=14            → a shorter window
 *   php artisan revalidations:prune --failure-days=180   → keep failures longer
 *   php artisan revalidations:prune --site=winpalack     → one site's history
 *   php artisan revalidations:prune --dry-run            → report, delete nothing
 *   php artisan revalidations:prune --force              → no confirmation prompt
 *
 * ── Why this table needs a prune at all ─────────────────────────────────────
 *
 * `site_revalidations` gains a row every time content is saved, for every site
 * that content appears on — an observer-driven write nobody initiates by hand.
 * It is the only unbounded table with no retention story: the email validation
 * log has `email-validation:prune`, the promotion history is partitioned by
 * month, and this one simply grew.
 *
 * ── Two windows, because the rows are not worth the same ────────────────────
 *
 * A SUCCESS is worth a few weeks: it answers "is revalidation working", which
 * is a question about now. A FAILURE is the diagnostic — it carries the HTTP
 * status and the error text, and it is what somebody reads months later when a
 * site turns out to have been quietly stale. So failures are kept three times
 * longer by default, and the two windows are separate options rather than one
 * number that has to compromise between them.
 *
 * ── What is never deleted ───────────────────────────────────────────────────
 *
 * THE NEWEST ROW FOR EACH SITE, whatever its age. The Cache Health screen opens
 * on "when did this site last hear from us", and a site that has had no content
 * change in months would otherwise show an empty history — indistinguishable
 * from a site whose revalidation has never worked at all. One row per site is a
 * rounding error in storage and the difference between "quiet" and "broken".
 *
 * `sites.last_revalidation_*` is denormalised on the site row and is not
 * touched here, so the status an operator sees first survives regardless.
 *
 * Rows are deleted in id-ordered chunks: the table is the one that grows
 * fastest, and a single DELETE over months of history would hold one long
 * transaction over a table the observers are still writing to.
 */
class PruneSiteRevalidations extends Command
{
    protected $signature = 'revalidations:prune
        {--days=30 : Delete SUCCESSFUL attempts older than this many days}
        {--failure-days=90 : Delete FAILED attempts older than this many days}
        {--site= : Only this site (slug). Omit for every site.}
        {--dry-run : Report what would be deleted and delete nothing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete old rows from the revalidation log, keeping the newest attempt per site';

    /** Rows removed per round-trip. */
    private const int CHUNK = 1000;

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $failureDays = (int) $this->option('failure-days');

        if ($days < 1 || $failureDays < 1) {
            $this->error('--days and --failure-days must be at least 1. Use --dry-run to see what a window would remove.');

            return self::INVALID;
        }

        $site = $this->site();

        if ($site === false) {
            return self::INVALID;
        }

        try {
            $successCutoff = Carbon::now()->subDays($days);
            $failureCutoff = Carbon::now()->subDays($failureDays);
        } catch (InvalidFormatException) {
            $this->error('Could not read the retention window.');

            return self::INVALID;
        }

        // Resolved ONCE, before anything is deleted, and excluded from every
        // pass — see the class note on why the newest row per site stays.
        $keepIds = $this->newestPerSite($site);

        $total = $this->query($successCutoff, $failureCutoff, $site, $keepIds)->count();

        if ($total === 0) {
            $this->info('Nothing to prune — no attempts older than the retention windows.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $this->reportDryRun($successCutoff, $failureCutoff, $site, $keepIds, $total);

            return self::SUCCESS;
        }

        if (! $this->confirmPrune($total, $successCutoff, $failureCutoff, $site)) {
            return self::SUCCESS;
        }

        $deleted = $this->deleteInChunks($successCutoff, $failureCutoff, $site, $keepIds, $total);

        $this->newLine();
        $this->info(sprintf('Deleted %d revalidation attempt(s).', $deleted));
        $this->comment(sprintf('Kept the newest attempt for each of %d site(s), whatever its age.', $keepIds->count()));

        return self::SUCCESS;
    }

    /**
     * The --site option resolved to a Site, or null when omitted.
     *
     * An unknown slug is an error rather than an empty match: deleting nothing
     * looks exactly like a successful run, and a typo on a cleanup command
     * should never read as "done".
     *
     * @return Site|false|null  false signals a reported failure to the caller.
     */
    private function site(): Site|false|null
    {
        $slug = trim((string) $this->option('site'));

        if ($slug === '') {
            return null;
        }

        $site = Site::query()->where('slug', $slug)->first();

        if ($site === null) {
            $known = Site::query()->orderBy('slug')->pluck('slug')->implode(', ');
            $this->error("No site with slug \"{$slug}\". Registered slugs: {$known}");

            return false;
        }

        return $site;
    }

    /**
     * The id of the newest attempt for each site — the rows this never deletes.
     *
     * One grouped query rather than one per site, and it returns ids rather
     * than rows: the result is only ever used as a NOT IN list.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function newestPerSite(?Site $site): \Illuminate\Support\Collection
    {
        return SiteRevalidation::query()
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->selectRaw('MAX(id) as id')
            ->groupBy('site_id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id);
    }

    /**
     * The set to delete: old successes, older failures, never the newest per site.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $keepIds
     * @return Builder<SiteRevalidation>
     */
    private function query(
        Carbon $successCutoff,
        Carbon $failureCutoff,
        ?Site $site,
        \Illuminate\Support\Collection $keepIds,
    ): Builder {
        return SiteRevalidation::query()
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->when($keepIds->isNotEmpty(), fn (Builder $q) => $q->whereNotIn('id', $keepIds->all()))
            ->where(function (Builder $q) use ($successCutoff, $failureCutoff): void {
                // Served by the (status, created_at) index, one branch each.
                $q->where(function (Builder $ok) use ($successCutoff): void {
                    $ok->where('status', SiteRevalidation::STATUS_SUCCESS)
                        ->where('created_at', '<', $successCutoff);
                })->orWhere(function (Builder $bad) use ($failureCutoff): void {
                    $bad->where('status', SiteRevalidation::STATUS_FAILED)
                        ->where('created_at', '<', $failureCutoff);
                });
            });
    }

    /** @param  \Illuminate\Support\Collection<int, int>  $keepIds */
    private function reportDryRun(
        Carbon $successCutoff,
        Carbon $failureCutoff,
        ?Site $site,
        \Illuminate\Support\Collection $keepIds,
        int $total,
    ): void {
        $successes = $this->query($successCutoff, $failureCutoff, $site, $keepIds)
            ->where('status', SiteRevalidation::STATUS_SUCCESS)->count();

        $this->info('Dry run — nothing was deleted.');
        $this->line($this->scope($site));
        $this->line(sprintf('  Would delete: %d attempt(s)', $total));
        $this->line(sprintf('    successes before %s: %d', $successCutoff->toDateTimeString(), $successes));
        $this->line(sprintf('    failures  before %s: %d', $failureCutoff->toDateTimeString(), $total - $successes));
        $this->line(sprintf('  Kept regardless of age: the newest attempt for %d site(s)', $keepIds->count()));
        $this->line(sprintf('  Remaining after a real run: %d', $this->remaining($site) - $total));
    }

    private function remaining(?Site $site): int
    {
        return SiteRevalidation::query()
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->count();
    }

    /** Show the blast radius and get a yes, unless --force was passed. */
    private function confirmPrune(int $total, Carbon $successCutoff, Carbon $failureCutoff, ?Site $site): bool
    {
        $this->warn(sprintf(
            'About to delete %d revalidation attempt(s): successes before %s, failures before %s.',
            $total,
            $successCutoff->toDateString(),
            $failureCutoff->toDateString(),
        ));
        $this->warn($this->scope($site));
        $this->comment('The newest attempt for every site is kept, so Cache Health never goes blank.');

        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Refusing to delete without confirmation. Pass --force when running non-interactively.');

            return false;
        }

        if (! $this->confirm('Proceed?', false)) {
            $this->info('Aborted; nothing was deleted.');

            return false;
        }

        return true;
    }

    private function scope(?Site $site): string
    {
        return $site === null
            ? 'Scope: every site.'
            : "Scope: {$site->name} ({$site->slug}) only.";
    }

    /**
     * Delete in id-ordered chunks.
     *
     * Each pass re-runs the same query and the deleted rows are gone, so the
     * set shrinks until empty. Nothing larger than one chunk of ids is held in
     * memory, and no single transaction spans months of history on a table the
     * observers are still writing to.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $keepIds
     */
    private function deleteInChunks(
        Carbon $successCutoff,
        Carbon $failureCutoff,
        ?Site $site,
        \Illuminate\Support\Collection $keepIds,
        int $total,
    ): int {
        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $deleted = 0;

        while (true) {
            $ids = $this->query($successCutoff, $failureCutoff, $site, $keepIds)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $removed = SiteRevalidation::query()->whereKey($ids)->delete();

            // A pass that matches rows but removes none would spin forever.
            if ($removed === 0) {
                $progress->finish();
                $this->newLine();
                $this->error('Delete affected no rows; stopping to avoid an endless loop.');

                return $deleted;
            }

            $deleted += $removed;
            $progress->advance($removed);
        }

        $progress->finish();

        return $deleted;
    }
}
