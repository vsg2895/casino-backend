<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CampaignReceiver;
use App\Models\EmailSchedule;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\ScheduleSendHistory;
use App\Models\Unsubscribe;
use Carbon\CarbonInterface;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * THE single definition of "who receives this schedule's campaign".
 *
 * Every consumer — the scheduled send itself, the admin recipient preview, the
 * count, and the CSV export — goes through this class, so the four can never
 * drift apart. Nothing else may rebuild the audience query.
 *
 * The audience is "the newest `limit` addresses of the schedule's list, minus
 * anyone who cannot or should not be mailed now". The list is one of:
 *
 *   newsletters         the site's subscribers, minus anyone this site's
 *                       promotion reached in the last 24h (the dedup shared
 *                       with the after-verification email)
 *   mailgun_receivers   Custom Receivers → Mailgun, minus the list's own
 *   sendgrid_receivers  Custom Receivers → SendGrid, unsubscribes, suppressions
 *                       and anyone it already mailed today (its one-per-day claim)
 *   warmup              the warmup list
 *
 * and, for EVERY audience, minus:
 *
 *  - anyone who OPTED OUT OF THIS SITE, through any template and on any list.
 *    Until 2026-11-17 this was checked for `newsletters` only, so a person who
 *    unsubscribed from a site could still be mailed by that same site through a
 *    Custom Receivers list their address also happened to be on. The opt-out is
 *    the site's, not the list's, so it is applied to all four. A schedule with
 *    `include_unsubscribed` deliberately turned on is the one exception;
 *  - anyone RESTING: who received THIS SCHEDULE'S TEMPLATE within its
 *    `cooldown_days` ({@see ScheduleSendHistory::scopeResting()}).
 *
 * Filtering happens BEFORE the cap, as warmup's cooldown does: the newest N
 * that may be mailed, not the newest N minus those that may not. So each run
 * walks further down the list on its own instead of re-offering the same head.
 *
 * Every traversal is keyset-paginated on (created_at, id), newest first
 * ({@see stream()}): memory stays flat regardless of list size, and a row
 * inserted mid-run cannot shift the window.
 */
class ScheduleRecipientService
{
    /** Rows fetched per round-trip when streaming. */
    public const int STREAM_CHUNK = 500;

    /** The columns every list shares, and all any consumer reads. */
    private const array COLUMNS = ['id', 'email', 'created_at'];

    /**
     * Every eligible address, newest first — NOT yet capped at `limit`.
     * Never executed here; callers add their own projection.
     */
    public function query(EmailSchedule $schedule, ?CarbonInterface $now = null): Builder
    {
        $now ??= now();
        $model = $schedule->audienceModel();

        $query = $model === null
            ? $this->newsletters($schedule, $now)
            : $this->list($model);

        $table = $query->getModel()->getTable();
        $siteId = (int) $schedule->site_id;
        $days = $schedule->cooldownDays();
        // The cooldown rests an address against the template it received, not
        // against the promotion template every schedule used to send.
        $template = $schedule->templateKey();

        if ($schedule->honoursOptOut()) {
            self::excludeOptedOut($query, $siteId, $table);
        }

        return $query
            ->whereNotExists(static function (QueryBuilder $sub) use ($siteId, $days, $table, $now, $template): void {
                ScheduleSendHistory::scopeResting($sub, $siteId, $template, $days, $table . '.email', $now);
            })
            ->orderByDesc($table . '.created_at')
            ->orderByDesc($table . '.id');
    }

    /** Exactly how many addresses the next run would mail: eligible, capped at `limit`. */
    public function count(EmailSchedule $schedule, ?CarbonInterface $now = null): int
    {
        $limit = $schedule->runLimit();

        if ($limit < 1) {
            return 0;
        }

        return min($limit, $this->query($schedule, $now)->reorder()->count());
    }

    /**
     * The first $limit recipients, in the order the campaign processes them.
     *
     * @return Collection<int, Model>
     */
    public function sample(EmailSchedule $schedule, int $limit = 25, ?CarbonInterface $now = null): Collection
    {
        $rows = new Collection();

        foreach ($this->stream($schedule, min($limit, self::STREAM_CHUNK), $now) as $chunk) {
            foreach ($chunk as $recipient) {
                $rows->push($recipient);

                if ($rows->count() >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    /**
     * Hand every recipient to $callback in chunks of $size. Used by the campaign
     * fan-out, so the send and the preview/export share one traversal.
     *
     * @param  callable(Collection<int, Model>): void  $callback
     */
    public function eachChunk(EmailSchedule $schedule, int $size, callable $callback, ?CarbonInterface $now = null): void
    {
        foreach ($this->stream($schedule, $size, $now) as $chunk) {
            $callback($chunk);
        }
    }

    /**
     * Rows for the CSV export: [email, created_at] and nothing else.
     *
     * A generator, so {@see \App\Support\CsvExport} streams straight to the
     * client and the full list is never held in memory.
     *
     * @return Generator<int, array{0: string, 1: string}>
     */
    public function exportRows(EmailSchedule $schedule, ?CarbonInterface $now = null): Generator
    {
        foreach ($this->stream($schedule, self::STREAM_CHUNK, $now) as $chunk) {
            foreach ($chunk as $recipient) {
                yield [
                    (string) $recipient->email,
                    $recipient->created_at?->toDateTimeString() ?? '',
                ];
            }
        }
    }

    /**
     * The site's subscribers who may receive mail now.
     *
     * The opt-out check is NOT here any more — it is applied to every audience
     * in {@see query()}, because it is the site's opt-out and not this list's.
     *
     * The 24h promotion dedup stays: it is shared with the after-verification
     * email, which a schedule's own history knows nothing about.
     */
    private function newsletters(EmailSchedule $schedule, CarbonInterface $now): Builder
    {
        $siteId = (int) $schedule->site_id;

        return Newsletter::query()
            ->where('site_id', $siteId)
            ->whereNotExists(static function (QueryBuilder $sub) use ($siteId, $now): void {
                PromotionEmailHistory::scopeDeliveredWithinDay($sub, $siteId, 'newsletters.email', $now);
            });
    }

    /**
     * THE opt-out predicate, for any table with an `email` column.
     *
     * One definition, used by the audience query here and by the send-time
     * re-check in both batch jobs, so an address that opts out between the
     * fan-out and its batch is still dropped.
     *
     * Not scoped to a `type`: an unsubscribe recorded through the verify mail
     * stops the promotion too. That is what {@see Unsubscribe::hasAny()} means
     * and what the unsubscribe pages have always promised.
     *
     * @param  Builder<Model>  $query
     */
    public static function excludeOptedOut(Builder $query, int $siteId, string $table): void
    {
        $query->whereNotExists(static function (QueryBuilder $sub) use ($siteId, $table): void {
            $sub->from('unsubscribes')
                ->whereColumn('unsubscribes.email', $table . '.email')
                ->where('unsubscribes.site_id', $siteId);
        });
    }

    /**
     * Which of $emails have opted out of this site — the send-time twin of
     * {@see excludeOptedOut()}, one query for a whole batch.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    public static function optedOutAmong(int $siteId, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return Unsubscribe::query()
            ->where('site_id', $siteId)
            ->whereIn('email', $emails)
            ->distinct()
            ->pluck('email')
            ->all();
    }

    /**
     * A global list. Receivers keep their own rules — never an address that
     * unsubscribed or is suppressed, and never one the list already mailed
     * today — so a schedule cannot reach someone the list itself would not.
     *
     * @param  class-string<Model>  $model
     */
    private function list(string $model): Builder
    {
        // Every global list is sendable-scoped: warmup gained its own opt-out
        // in 2026-12 and honours it here exactly as the receiver lists do.
        $query = $model::query()->sendable();

        // The rest is the receiver lists' own predicate: the shared suppression
        // list and the one-email-per-address-per-day claim. Warmup has neither
        // — seed mailboxes are not on the suppression list, and warmup paces
        // itself with `last_sent_at`.
        if (is_subclass_of($model, CampaignReceiver::class)) {
            $query->notSuppressed()->notClaimedToday();
        }

        return $query;
    }

    /**
     * The one traversal every consumer is built on: keyset pagination over the
     * eligible set, newest first, stopping at `limit` rows.
     *
     * @return Generator<int, Collection<int, Model>>
     */
    private function stream(EmailSchedule $schedule, int $size, ?CarbonInterface $now = null): Generator
    {
        $now ??= now();
        $remaining = $schedule->runLimit();
        $size = max(1, $size);
        $base = $this->query($schedule, $now);
        $table = $base->getModel()->getTable();
        $cursor = null;

        while ($remaining > 0) {
            $page = (clone $base)->limit(min($size, $remaining));

            if ($cursor !== null) {
                [$lastCreatedAt, $lastId] = $cursor;

                $page->where(static function (Builder $q) use ($lastCreatedAt, $lastId, $table): void {
                    $q->where($table . '.created_at', '<', $lastCreatedAt)
                        ->orWhere(static function (Builder $tie) use ($lastCreatedAt, $lastId, $table): void {
                            $tie->where($table . '.created_at', '=', $lastCreatedAt)
                                ->where($table . '.id', '<', $lastId);
                        });
                });
            }

            $rows = $page->get(array_map(static fn (string $column): string => $table . '.' . $column, self::COLUMNS));

            if ($rows->isEmpty()) {
                return;
            }

            $remaining -= $rows->count();
            $last = $rows->last();
            $cursor = [$last->created_at, (int) $last->id];

            yield $rows;

            unset($rows, $last);
        }
    }
}
