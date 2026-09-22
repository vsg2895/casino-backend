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

    protected $fillable = [
        'site_id',
        'forum_post_id',
        'forum_user_id',
        'reason',
        'note',
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
