<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A visitor's flag on a post. */
class ForumReport extends Model
{
    public const string STATUS_OPEN = 'open';

    public const string STATUS_ACTIONED = 'actioned';

    public const string STATUS_DISMISSED = 'dismissed';

    /** @var list<string> */
    public const array REASONS = ['spam', 'abuse', 'off_topic', 'other'];

    /**
     * `reporter_ip`, `status` and the `handled_*` pair must be here: the report
     * endpoint sets them through updateOrCreate(), and without them fill()
     * dropped them silently — every report landed with no IP, and a re-report
     * never reopened a dismissed one. All four values are server-chosen, never
     * request input.
     */
    protected $fillable = [
        'site_id',
        'forum_post_id',
        'forum_user_id',
        'reporter_ip',
        'reason',
        'note',
        'status',
        'handled_by',
        'handled_at',
    ];

    protected $hidden = ['reporter_ip'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'forum_post_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(ForumUser::class, 'forum_user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
