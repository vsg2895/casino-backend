<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'api_key',
        'revalidation_url',
        'settings',
        'active',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'active' => 'boolean',
        ];
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom('name')
            ->saveSlugsTo('slug')
            ->doNotGenerateSlugsOnUpdate(); // slug must never change — it's part of the public API path
    }

    public static function generateApiKey(): string
    {
        return Str::random(64);
    }

    public function rotateApiKey(): string
    {
        $plain = self::generateApiKey();
        $this->update(['api_key' => Hash::make($plain)]);

        return $plain;
    }

    public function casinos(): BelongsToMany
    {
        return $this->belongsToMany(Casino::class)
            ->withPivot(['affiliate_url', 'position', 'featured', 'active'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function newsletters(): HasMany
    {
        return $this->hasMany(Newsletter::class);
    }

    public function unsubscribes(): HasMany
    {
        return $this->hasMany(Unsubscribe::class);
    }

    /**
     * Base URL of this site's public front-end, used to build email links
     * (e.g. the unsubscribe page). Defaults to the registered domain over
     * HTTPS, but a global override (UNSUBSCRIBE_BASE_URL) lets every link point
     * at e.g. http://localhost:3000 for local development / testing.
     */
    public function frontendBaseUrl(): string
    {
        // Env-aware default (localhost when APP_DEBUG, live domain otherwise);
        // UNSUBSCRIBE_BASE_URL still overrides everything when set.
        $override = config('services.unsubscribe.base_url');
        $default = config('urls.sites.' . $this->slug, 'https://' . $this->domain);
        $base = is_string($override) && $override !== '' ? $override : $default;

        return rtrim((string) $base, '/');
    }

    public function emailTemplate(): HasOne
    {
        return $this->hasOne(SiteEmailTemplate::class);
    }

    /**
     * The site's subscription email template, creating it with sensible
     * defaults on first access so every site always has one.
     */
    public function emailTemplateOrDefault(): SiteEmailTemplate
    {
        return $this->emailTemplate()->firstOrCreate(
            [],
            SiteEmailTemplate::defaultsFor($this),
        );
    }

    public function promotionEmail(): HasOne
    {
        return $this->hasOne(SitePromotionEmail::class);
    }

    /**
     * The site's promotion email template, creating it with sensible defaults
     * on first access so every site always has one.
     */
    public function promotionEmailOrDefault(): SitePromotionEmail
    {
        return $this->promotionEmail()->firstOrCreate(
            [],
            SitePromotionEmail::defaultsFor($this),
        );
    }

    public function verifyEmail(): HasOne
    {
        return $this->hasOne(SiteVerifyEmail::class);
    }

    /**
     * The site's "verify your email" template, creating it with sensible
     * defaults on first access so every site always has one.
     */
    public function verifyEmailOrDefault(): SiteVerifyEmail
    {
        return $this->verifyEmail()->firstOrCreate(
            [],
            SiteVerifyEmail::defaultsFor($this),
        );
    }
}
