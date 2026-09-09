<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor-written review of a casino, submitted on one site.
 *
 * Moderated either BEFORE or AFTER publication, per site
 * (`sites.review_auto_publish`). With it on — the default — a submission is
 * created already PUBLISHED and a moderator hides what does not belong; with it
 * off, nothing reaches the public API until an admin publishes it.
 *
 * The three states stay distinct under both regimes, and that is the point:
 * PENDING is "nobody has looked at this yet", HIDDEN is "looked at and
 * rejected". Collapsing them into a boolean would lose the queue that tells an
 * admin there is work waiting — and under post-moderation it would also lose the
 * difference between a review that was never screened and one that was taken
 * down deliberately.
 *
 * `author_email` is deliberately absent from every public response. It exists so
 * a moderator can recognise a repeat submitter; it is not part of the review.
 */
class CasinoReview extends Model
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PUBLISHED = 'published';
    public const string STATUS_HIDDEN = 'hidden';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_PENDING, self::STATUS_PUBLISHED, self::STATUS_HIDDEN];

    public const int MIN_RATING = 1;
    public const int MAX_RATING = 5;

    protected $fillable = [
        'site_id',
        'casino_id',
        'author_name',
        'author_email',
        'rating',
        'title',
        'body',
        'status',
        'published_at',
    ];

    /**
     * Never serialised, even by accident.
     *
     * The public resource omits it explicitly too — this is the second line of
     * defence, the same belt-and-braces the Site model uses for `api_key`.
     */
    protected $hidden = ['author_email'];

    protected function casts(): array
    {
        return [
            'rating'       => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function casino(): BelongsTo
    {
        return $this->belongsTo(Casino::class);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Publish or hide, keeping `published_at` honest.
     *
     * One method so the timestamp can never disagree with the status: publishing
     * stamps it if it was never set, hiding clears nothing — the date it first
     * went live is worth keeping.
     */
    public function setPublished(bool $published): void
    {
        $this->status = $published ? self::STATUS_PUBLISHED : self::STATUS_HIDDEN;

        if ($published && $this->published_at === null) {
            $this->published_at = now();
        }

        $this->save();
    }

    /**
     * The only rows the public may see.
     *
     * @param  Builder<CasinoReview>  $query
     * @return Builder<CasinoReview>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * @param  Builder<CasinoReview>  $query
     * @return Builder<CasinoReview>
     */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    /**
     * @param  Builder<CasinoReview>  $query
     * @return Builder<CasinoReview>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        // LIKE metacharacters in user input would otherwise turn a search for
        // "100%" into a match-everything wildcard.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where(static function (Builder $q) use ($escaped): void {
            $q->where('author_name', 'like', '%' . $escaped . '%')
                ->orWhere('title', 'like', '%' . $escaped . '%')
                ->orWhere('body', 'like', '%' . $escaped . '%');
        });
    }
}
