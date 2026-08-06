<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One address on the email-warmup list.
 *
 * Warmup traffic exists to build the reputation of the sending mailbox, so the
 * list is global rather than per-site, and carries no unsubscribe token: these
 * are not marketing recipients and never receive campaign mail.
 */
class WarmupEmail extends Model
{
    protected $fillable = ['email'];

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
}
