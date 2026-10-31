<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AuthorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A real person who writes or checks one site's content.
 *
 * Belongs to exactly one site. Nothing about an author is public until
 * `visible` is on: the public byline, the /authors/{slug} page and the sitemap
 * entry all filter on it, so a half-written profile stays private.
 *
 * Bylines point here from three places: `casino_site` (casinos are global, so
 * their byline is per attachment), `articles` and `cms_pages`.
 */
class Author extends Model
{
    /** @use HasFactory<AuthorFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'slug',
        'photo_path',
        'role',
        'short_bio',
        'long_bio',
        'expertise',
        'profile_links',
        'visible',
    ];

    protected function casts(): array
    {
        return [
            'expertise'     => 'array',
            'profile_links' => 'array',
            'visible'       => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Generated from the name when left blank, and never regenerated: the
        // slug is the public URL of the author page.
        static::creating(function (self $author): void {
            if (trim((string) $author->slug) === '') {
                $author->slug = Str::slug((string) $author->name);
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Articles this person wrote. */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'author_id');
    }

    /** @param Builder<Author> $query */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('visible', true);
    }

    /** @param Builder<Author> $query */
    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    /**
     * The fields a byline needs, and nothing a reader should not see.
     *
     * Built here so the casino, article and page responses cannot drift apart
     * in what they reveal about a person.
     *
     * @return array{name: string, slug: string, role: ?string, photo_path: ?string}
     */
    public function toByline(): array
    {
        return [
            'name'       => (string) $this->name,
            'slug'       => (string) $this->slug,
            'role'       => $this->role,
            'photo_path' => $this->photo_path,
        ];
    }
}
