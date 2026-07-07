<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SpecialOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class SpecialOffer extends Model
{
    /** @use HasFactory<SpecialOfferFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'casino_id',
        'title',
        'slug',
        'image_path',
        'banner_image',
        'bonuses',
        'affiliate_url',
        'description',
        'rating',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'rating'     => 'integer',
            'sort_order' => 'integer',
            'active'     => 'boolean',
        ];
    }

    /**
     * Slug lifecycle: generated on create/duplicate and REGENERATED whenever the
     * title changes; left untouched on any other update. Format is user-friendly
     * and title-based — the title as lowercase words joined by "_", then a final
     * "_" and a short letters-only unique token, e.g. "welcome_bonus_kmxopt".
     */
    protected static function booted(): void
    {
        static::saving(function (self $offer): void {
            if (blank($offer->slug) || ($offer->exists && $offer->isDirty('title'))) {
                $offer->slug = static::generateUniqueSlug((string) $offer->title, $offer->getKey());
            }
        });
    }

    /**
     * Build a unique title-based slug: {title_words_by_underscore}_{letters}.
     * Retries the token until the slug is unique (excludes the given id / trashed).
     */
    public static function generateUniqueSlug(string $title, int|string|null $ignoreId = null): string
    {
        $base = static::slugBase($title);

        do {
            $slug = $base . '_' . static::slugToken();
        } while (
            static::withTrashed()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
                ->exists()
        );

        return $slug;
    }

    /** Title → lowercase ascii with non-alphanumerics collapsed to "_" ("offer" if empty). */
    private static function slugBase(string $title): string
    {
        $base = trim((string) Str::of($title)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_'), '_');

        return $base !== '' ? $base : 'offer';
    }

    /** Short lowercase LETTERS-ONLY token — the unique part after the last "_". */
    public static function slugToken(int $length = 6): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $token = '';

        for ($i = 0; $i < $length; $i++) {
            $token .= $letters[random_int(0, 25)];
        }

        return $token;
    }

    public function casino(): BelongsTo
    {
        return $this->belongsTo(Casino::class);
    }
}
