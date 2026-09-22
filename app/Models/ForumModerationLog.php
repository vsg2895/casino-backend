<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of who moderated what.
 *
 * `$timestamps = false` with an explicit `created_at`: a log entry is written
 * once and never edited, so an `updated_at` would be a column that can only
 * ever lie.
 */
class ForumModerationLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'user_id',
        'subject_type',
        'subject_id',
        'action',
        'reason',
        'from_status',
        'to_status',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Write one entry.
     *
     * Static rather than an observer: a moderation action is an intentional act
     * with a reason, and deriving it from a model event would log every
     * incidental status change — including the ones the counter observers make
     * — as though a human had decided it.
     */
    public static function record(
        int $siteId,
        ?int $actorId,
        string $subjectType,
        int $subjectId,
        string $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $reason = null,
    ): void {
        self::create([
            'site_id'      => $siteId,
            'user_id'      => $actorId,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'action'       => $action,
            'from_status'  => $fromStatus,
            'to_status'    => $toStatus,
            'reason'       => $reason,
            'created_at'   => now(),
        ]);
    }
}
