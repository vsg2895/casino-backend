<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A member's message, or a reply to one.
 *
 * Depth 0 is a post, depth 1 is a comment, and there is no depth 2 — enforced by
 * a CHECK constraint, not only by this class. See the migration.
 */
class ForumPost extends Model
{
    use SoftDeletes;

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_SPAM = 'spam';

    /** @var list<string> */
    public const array STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SPAM,
    ];

    public const int DEPTH_POST = 0;

    public const int DEPTH_COMMENT = 1;

    protected $fillable = [
        'site_id',
        'forum_article_id',
        'parent_id',
        'depth',
        'forum_user_id',
        'user_id',
        'body',
        'status',
    ];

    /** The raw address is for moderators, never for a public resource. */
    protected $hidden = ['ip_address'];

    protected function casts(): array
    {
        return [
            'depth'       => 'integer',
            'approved_at' => 'datetime',
            'edited_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // depth is derived, never supplied. Trusting a caller to set it
        // consistently with parent_id is exactly the mistake the CHECK
        // constraint exists to catch, so nothing is left to trust.
        static::saving(function (self $post): void {
            $post->depth = $post->parent_id === null ? self::DEPTH_POST : self::DEPTH_COMMENT;

            /*
             * EXACTLY ONE AUTHOR. A post belongs either to a registered member
             * or to the editorial team, never to both and never to neither.
             *
             * Enforced here rather than as a CHECK constraint because the test
             * suite runs on SQLite and the application on MySQL — a constraint
             * that exists on only one of them is worse than a rule that runs on
             * both. Without it, a post with neither author renders as nobody
             * and a post with both would be a contradiction nothing resolves.
             */
            $member = $post->forum_user_id !== null;
            $staff  = $post->user_id !== null;

            if ($member === $staff) {
                throw new \LogicException(
                    'A forum post needs exactly one author: forum_user_id for a member, user_id for the team.',
                );
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ForumArticle::class, 'forum_article_id');
    }

    /**
     * The MEMBER who wrote it. Null on an editorial reply.
     *
     * Kept as `author` rather than renamed: every existing caller, resource and
     * eager-load in the application already asks for it by this name, and the
     * member case is still the overwhelming majority of posts.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(ForumUser::class, 'forum_user_id');
    }

    /** The ADMIN who wrote it, when the editorial team did. Null otherwise. */
    public function staffAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Written by the editorial team rather than by a registered member. */
    public function isStaffAuthored(): bool
    {
        return $this->user_id !== null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ForumReport::class);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->where('depth', self::DEPTH_POST);
    }

    /** Whether this post currently counts toward any denormalised total. */
    public function countsTowardTotals(): bool
    {
        return $this->status === self::STATUS_APPROVED && $this->deleted_at === null;
    }
}
