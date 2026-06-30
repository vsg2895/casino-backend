<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SpecialOfferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class SpecialOffer extends Model
{
    /** @use HasFactory<SpecialOfferFactory> */
    use HasFactory, HasSlug, SoftDeletes;

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

    public function getSlugOptions(): SlugOptions
    {
        // Name-based slug + a short, SEO-friendly unique suffix, e.g.
        // "welcome-bonus-k7m2p9". The suffix always starts with a letter (never
        // numbers-only) so every offer gets its own readable URL even when titles
        // repeat across casinos — no ugly "-1"/"-2" or all-digit suffixes.
        return SlugOptions::create()
            ->generateSlugsFrom(fn (SpecialOffer $offer): string => trim((string) $offer->title) . ' ' . self::slugToken())
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate(); // keep the public URL stable when an offer is renamed
    }

    /** Short lowercase alphanumeric token that always begins with a letter. */
    public static function slugToken(int $length = 6): string
    {
        $letters = 'abcdefghijklmnopqrstuvwxyz';
        $alnum = $letters . '0123456789';

        $token = $letters[random_int(0, 25)]; // first char is always a letter
        for ($i = 1; $i < $length; $i++) {
            $token .= $alnum[random_int(0, 35)];
        }

        return $token;
    }

    public function casino(): BelongsTo
    {
        return $this->belongsTo(Casino::class);
    }
}
