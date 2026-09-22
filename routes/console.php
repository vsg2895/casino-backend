<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// THE OVERLAP EXPIRY IS NOT COSMETIC. `withoutOverlapping()` with no argument
// holds its cache mutex for 24 HOURS. The mutex is released in a shutdown
// handler, so a run that is killed rather than finished — a deploy mid-tick, an
// OOM, a `kill -9`, a Redis restart that drops the release — leaves the lock
// behind and the command then silently no-ops EVERY MINUTE FOR A DAY. Nothing
// is logged, because the command never enters handle().
//
// Both sweeps finish in well under a second on any realistic list, so an
// explicit few minutes is far above any legitimate run and turns a stranded
// mutex from a day-long outage into a self-healing blip.
//
// If one is stuck right now: `php artisan schedule:clear-cache`.
//
// The 5 is written out at each call rather than hoisted into a constant: this
// file is re-evaluated on every application boot, and a file-scope `const` is
// then redefined on the second one.

// Check every minute for promotion campaigns that are due to run. Requires the
// system cron entry: `* * * * * php artisan schedule:run`.
Schedule::command('promotions:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(5);

// Queue the global post-verification promotion for subscribers whose
// `newsletters.verified_at + delay_minutes` has elapsed. Every minute so the
// promotion lands close to the intended delay after the subscriber clicked
// their verify link, rather than drifting on a coarse tick.
//
// DELIBERATELY NO `withoutOverlapping()`. This sweep only SELECTS and
// dispatches; every candidate is claimed atomically inside the job with a
// conditional UPDATE on `verification_promotion_sent_at`, so overlapping runs
// cannot double-send — the guarantee is in InnoDB, not in a cache lock.
//
// The mutex therefore bought nothing, while its failure mode was total: a
// stranded lock silently muted this command, and because the lock's TTL is
// fixed WHEN IT IS TAKEN, shipping a shorter expiry does not shorten one that
// is already stuck. Removing it means a killed run costs one missed minute
// instead of needing `schedule:clear-cache` to recover.
Schedule::command('promotions:dispatch-verification')
    ->everyMinute();

// Provision upcoming monthly partitions for the promotion history table.
// Mailgun receiver sends. Hourly rather than every minute: the cooldown is
// expressed in DAYS, so a finer tick would only re-check a set that cannot have
// changed. No withoutOverlapping() — the command only dispatches, and the
// duplicate guard lives in the database, so a stranded lock would be a bigger
// risk than an overlapping tick.
//Schedule::command('mailgun:dispatch-receivers')->hourly();

Schedule::command('promotions:manage-history-partitions')
    ->monthlyOn(1, '04:30')
    ->withoutOverlapping();

//Schedule::command('test:command')->everyMinute();

// Delete token rows that have already expired. Purely housekeeping: an expired
// token is rejected by Sanctum whether or not the row still exists, so this
// keeps `personal_access_tokens` from growing without bound and nothing more.
// The 24-hour grace leaves a recently expired token visible long enough to
// answer "was I signed out, or did something else happen?".
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Revalidation attempts accumulate at roughly (sites x saves) per day. Thirty
// days is well past the point where an old attempt tells anyone anything, and
// keeping them forever would make the history query slower every week.
Schedule::call(function (): void {
    App\Models\SiteRevalidation::where('created_at', '<', now()->subDays(30))->delete();
})->daily()->name('prune-site-revalidations');

// Retire validation logs past their window. These rows carry visitor email
// addresses, so retention is a privacy obligation rather than housekeeping.
// Monthly: the window is expressed in months, so a finer tick would only
// re-check a set that cannot have changed.
Schedule::command('email-validation:prune')->monthlyOn(1, '03:20');

/*
 * Rebuild the search index nightly.
 *
 * The index is derived data: observers keep it current as content is saved, but
 * nothing populates it for content that ALREADY existed. That gap is exactly how
 * production ended up returning an empty result for every search — the migration
 * created the table, the deploy never ran `search:reindex`, and the observers had
 * nothing to react to because nobody edited anything afterwards.
 *
 * A nightly rebuild makes the index self-healing: a fresh deploy, a restored
 * database or a missed observer write all correct themselves within a day
 * instead of leaving search silently broken until somebody reports it.
 *
 * Affordable because it is small — a few hundred rows across every site — and
 * idempotent, so a run that overlaps a content edit cannot corrupt anything.
 * `--prune` also drops rows whose source entity has since been deleted.
 */
Schedule::command('search:reindex --prune')->dailyAt('04:10')->withoutOverlapping();

/*
 * ── Community forum ──────────────────────────────────────────────────────────
 */

/*
 * Drain the Redis view buffer into forum_articles.views_count.
 *
 * Every minute, and the window is deliberately short: a view count a minute
 * stale is indistinguishable from a live one to a reader, while a longer window
 * means losing more if Redis goes away.
 *
 * withoutOverlapping because the flush RENAMES the buffer key before reading it
 * — two concurrent runs would have the second find nothing and the first hold a
 * scratch key longer than it needs to.
 */
Schedule::command('forum:flush-views')->everyMinute()->withoutOverlapping();

/*
 * Recompute the Hot Threads ranking.
 *
 * The rank is an expression (views + recent replies), and an ORDER BY over an
 * expression can never use an index — so it is materialised into `hot_score`
 * here and served from forum_articles_hot_idx. Every ten minutes is the trade:
 * the tab is minutes behind, instead of every request paying for a filesort.
 */
Schedule::command('forum:rescore')->everyTenMinutes()->withoutOverlapping();

/*
 * Nightly counter reconciliation.
 *
 * The observers keep the totals correct in normal operation; this is the safety
 * net for a drift caused by a direct SQL edit, an interrupted deploy or a bug in
 * a future observer. Chunked and idempotent, so it is safe while people post.
 *
 * NOT --dry-run: the point is to repair, and a drift that is only reported is a
 * drift that stays.
 */
Schedule::command('forum:recount')->dailyAt('04:40')->withoutOverlapping();
