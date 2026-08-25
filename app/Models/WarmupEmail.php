<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One address on the email-warmup list.
 *
 * Warmup traffic exists to build the reputation of the SENDING mailbox, so the
 * list is global rather than per-site. A send renders one of the site email
 * templates for a chosen site, but the address itself belongs to no site — which
 * site's template it received is recorded on {@see WarmupSendRecipient}.
 *
 * TWO COLUMNS DRIVE SELECTION, and they do different jobs:
 *
 *  - `created_at`   ordering. A limited run takes the MOST RECENTLY ADDED
 *                   addresses first, because a freshly imported batch is what
 *                   the operator wants warmed next.
 *  - `last_sent_at` the cooldown filter. An address contacted inside the
 *                   configured window is skipped, which is what stops the newest
 *                   addresses absorbing every run. NULL = never contacted, and
 *                   is always eligible.
 *
 * `last_sent_at` is the denormalised mirror of the newest SUCCESSFUL
 * {@see WarmupSendRecipient} row for this address; both are written together by
 * {@see \App\Jobs\SendWarmupBatchJob}. Selection reads the column rather than the
 * log because it is one indexed value on the row already being scanned.
 */
class WarmupEmail extends Model
{
    protected $fillable = ['email'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
    }

    /** Every recorded delivery attempt for this address, newest first. */
    public function recipients(): HasMany
    {
        return $this->hasMany(WarmupSendRecipient::class);
    }

    /** Case-insensitive prefix search, matching the admin history search. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // Escape LIKE wildcards so user input cannot turn into a %..% scan.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where('email', 'like', $escaped . '%');
    }

    /**
     * Selection order: most recently added first.
     *
     * `id` is the tiebreaker, and not optional: an import writes thousands of
     * rows with an identical `created_at`, and without it a chunked read could
     * repeat or skip addresses. Served by `warmup_emails_recency_index`.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Only addresses not successfully contacted within the last $days days.
     *
     * NULL `last_sent_at` (never contacted) always qualifies — MySQL would drop
     * those rows from a plain `<=` comparison, which would make a fresh import
     * ineligible for its own first send.
     *
     * A null/zero $days means "no cooldown": every address qualifies. That is the
     * whole-list send, and it is expressed here rather than by the caller
     * branching, so every path shares one definition of eligibility.
     */
    public function scopeNotContactedWithin(Builder $query, ?int $days): Builder
    {
        if ($days === null || $days < 1) {
            return $query;
        }

        $cutoff = Carbon::now()->subDays($days);

        return $query->where(function (Builder $q) use ($cutoff): void {
            $q->whereNull('last_sent_at')->orWhere('last_sent_at', '<=', $cutoff);
        });
    }

    /**
     * Stamp the addresses just contacted, so the cooldown starts running.
     *
     * One UPDATE for the whole batch rather than a save() per address. Called
     * ONLY with addresses that were actually delivered to — a failed send must
     * not start a cooldown, or a permanently broken address would be retried
     * once every N days forever instead of on the next run.
     *
     * @param  list<string>  $emails
     */
    public static function markContacted(array $emails, ?Carbon $at = null): int
    {
        if ($emails === []) {
            return 0;
        }

        $now = $at ?? Carbon::now();

        return static::query()
            ->whereIn('email', $emails)
            ->update(['last_sent_at' => $now, 'updated_at' => $now]);
    }
}
