<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One address on the email-warmup list.
 *
 * Warmup traffic exists to build the reputation of the SENDING mailbox, so the
 * list is global rather than per-site. A send now renders one of the site email
 * templates for a chosen site, but the address itself still belongs to no site —
 * which site's template it received is recorded on {@see WarmupSend}, not here.
 *
 * `last_sent_at` drives the rotation: a run takes the least-recently-contacted
 * addresses first, so the whole list warms evenly instead of the same head of the
 * list absorbing every send.
 */
class WarmupEmail extends Model
{
    protected $fillable = ['email'];

    protected function casts(): array
    {
        return ['last_sent_at' => 'datetime'];
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
     * Rotation order: least recently contacted first.
     *
     * MySQL sorts NULLs first ascending, which is exactly right — an address that
     * has never been warmed should go before one contacted last month. `id` is the
     * tiebreaker, and not optional: an import writes thousands of rows sharing a
     * NULL `last_sent_at`, and without it a chunked read could repeat or skip
     * addresses. Served by the (last_sent_at, id) index.
     */
    public function scopeRotation(Builder $query): Builder
    {
        return $query->orderBy('last_sent_at')->orderBy('id');
    }

    /**
     * Stamp the addresses just contacted, so the next run rotates past them.
     *
     * One UPDATE for the whole batch rather than a save() per address.
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
