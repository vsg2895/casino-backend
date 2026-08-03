<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Newsletter;
use App\Models\Site;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Deletes newsletter subscribers, optionally only those created on or after a
 * given date.
 *
 *   php artisan newsletters:delete                      → every subscriber
 *   php artisan newsletters:delete --since=2026-07-01   → sign-ups from that day onward
 *   php artisan newsletters:delete --site=winpalack     → one site's list only
 *   php artisan newsletters:delete --hard               → rows gone from the table
 *
 * Every filter is optional and they compose: omitting them all targets every
 * subscriber of every site.
 *
 * `--since` is inclusive and resolves to 00:00:00 of the given day, so a whole
 * day is always covered regardless of sign-up time. `--site` takes the slug
 * registered in the admin — subscribers are tenant-scoped by `site_id`, so this
 * is what keeps one site's cleanup from touching another's list.
 *
 * Deletion is a SOFT delete by default (Newsletter uses SoftDeletes), which is
 * what makes the operation recoverable. `--hard` is optional and changes that to
 * a real `DELETE FROM newsletters`: no `deleted_at` is written because the row no
 * longer exists, and rows that were ALREADY soft-deleted are swept up in the same
 * pass — so a hard run leaves nothing behind for the matched range. Rows are
 * removed in chunks so a 50k list neither loads into memory nor holds one
 * enormous transaction.
 *
 * Opt-out records (`unsubscribes`) and send history (`promotion_email_histories`)
 * are keyed by email, not by subscriber id, and are deliberately left intact — an
 * unsubscribe must survive the deletion of the subscriber row, or a re-import
 * would start mailing someone who opted out.
 */
class DeleteNewsletters extends Command
{
    protected $signature = 'newsletters:delete
        {--since= : Only delete subscribers created on or after this date (Y-m-d). Omit to delete ALL.}
        {--site= : Only delete subscribers of this site (slug). Omit for every site.}
        {--hard : Physically DELETE the rows instead of soft-deleting, including any already soft-deleted}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Delete newsletter subscribers, optionally only those created since a given date';

    /** Rows removed per round-trip. */
    private const int CHUNK = 1000;

    public function handle(): int
    {
        try {
            $since = $this->since();
        } catch (InvalidFormatException) {
            $this->error('Invalid --since value. Use the Y-m-d format, e.g. --since=2026-07-01.');

            return self::INVALID;
        }

        $site = $this->site();

        if ($site === false) {
            return self::INVALID;
        }

        $hard = (bool) $this->option('hard');
        $total = $this->query($since, $site, $hard)->count();

        if ($total === 0) {
            $this->info('No matching subscribers — nothing to delete.');

            return self::SUCCESS;
        }

        if (! $this->confirmDeletion($total, $since, $site, $hard)) {
            return self::SUCCESS;
        }

        $deleted = $this->deleteInChunks($since, $site, $hard, $total);

        $this->newLine();
        $this->info(sprintf(
            '%s %d subscriber(s)%s%s.',
            $hard ? 'Permanently deleted' : 'Soft-deleted',
            $deleted,
            $site === null ? '' : ' for ' . $site->name,
            $since === null ? '' : ' created since ' . $since->toDateString(),
        ));

        if (! $hard) {
            $this->comment('Rows are recoverable (deleted_at set). Re-run with --hard to remove them from the table.');
        }

        return self::SUCCESS;
    }

    /** The --since option as a start-of-day instant, or null when omitted. */
    private function since(): ?Carbon
    {
        $raw = trim((string) $this->option('since'));

        if ($raw === '') {
            return null;
        }

        // '!' resets every unparsed field, so the result is exactly 00:00:00 of
        // that day. The round-trip check rejects input Carbon would otherwise
        // accept loosely (e.g. '2026-7-1' or '2026-13-01').
        $date = Carbon::createFromFormat('!Y-m-d', $raw);

        if ($date->format('Y-m-d') !== $raw) {
            throw new InvalidFormatException("Not a Y-m-d date: {$raw}");
        }

        return $date;
    }

    /**
     * The --site option resolved to a Site, or null when omitted.
     *
     * An unknown slug is an error rather than an empty match: silently deleting
     * nothing looks identical to a successful run, and a typo'd slug on a
     * cleanup command should never read as "done".
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
     * The set to delete. In hard mode already soft-deleted rows are included, so
     * the purge leaves nothing behind for the matched range.
     */
    private function query(?Carbon $since, ?Site $site, bool $hard): Builder
    {
        $query = $hard ? Newsletter::withTrashed() : Newsletter::query();

        if ($site !== null) {
            $query->where('site_id', $site->id);
        }

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        return $query;
    }

    /** Show the blast radius and get a yes, unless --force was passed. */
    private function confirmDeletion(int $total, ?Carbon $since, ?Site $site, bool $hard): bool
    {
        $scope = $since === null
            ? 'ALL newsletter subscribers'
            : "newsletter subscribers created since {$since->toDateString()}";

        $scope .= $site === null
            ? ', across every site'
            : ", on {$site->name} ({$site->slug})";

        $this->warn(sprintf(
            'About to %s %d row(s): %s%s.',
            $hard ? 'PERMANENTLY delete from the database' : 'soft-delete',
            $total,
            $scope,
            // Say it out loud: in hard mode the count already includes rows that
            // were only soft-deleted before, and they go too.
            $hard ? ' (including any already soft-deleted)' : '',
        ));

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

    /**
     * Delete in id-ordered chunks.
     *
     * Each pass re-runs the same query: soft-deleted rows fall out of the
     * default scope and force-deleted rows are gone, so the set shrinks until it
     * is empty. Nothing larger than one chunk of ids is ever held in memory.
     */
    private function deleteInChunks(?Carbon $since, ?Site $site, bool $hard, int $total): int
    {
        $progress = $this->output->createProgressBar($total);
        $progress->start();

        $deleted = 0;

        while (true) {
            $ids = $this->query($since, $site, $hard)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $removed = $hard
                ? Newsletter::withTrashed()->whereKey($ids)->forceDelete()
                : Newsletter::query()->whereKey($ids)->delete();

            // A pass that matches rows but removes none would spin forever.
            if ($removed === 0) {
                $this->newLine();
                $this->error('Delete affected no rows; stopping to avoid an endless loop.');

                break;
            }

            $deleted += $removed;
            $progress->advance($removed);
        }

        $progress->finish();

        return $deleted;
    }
}
