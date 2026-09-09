<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsToMany, HasOne, HasMany};
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Sluggable\{HasSlug, SlugOptions};

class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'positioning',
        'api_key',
        'revalidation_url',
        'settings',
        // Whether this site MAILS the people who subscribe to it. The form and
        // the stored subscriber are unaffected — see the migration.
        'newsletter_emails_enabled',
        'active',
        // Whether this site publishes the countries filter. Off by default —
        // see the migration that added it.
        'countries_enabled',
        // Whether this site displays AND accepts visitor reviews. Off by default.
        'reviews_enabled',
        'review_auto_publish',
        'operator_profile_enabled',
        'byline_enabled',
        'guides_enabled',
        'author_name',
        'author_role',
        'author_bio',
        'author_avatar_path',
        'methodology_page_slug',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'newsletter_emails_enabled' => 'boolean',
            'active' => 'boolean',
            'countries_enabled' => 'boolean',
            'reviews_enabled'     => 'boolean',
            'review_auto_publish' => 'boolean',
            'operator_profile_enabled' => 'boolean',
            'byline_enabled' => 'boolean',
            'guides_enabled' => 'boolean',
            'last_revalidated_at' => 'datetime',
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
     * Base URL of this site's public front-end, used to build the links baked
     * into emails (verify + unsubscribe pages). Resolved PER SITE so every link
     * points at that site's own real domain — these travel to real inboxes, so
     * they must be the live https URL, never localhost.
     *
     * Order: an explicit per-site override (config('urls.sites.{slug}'), i.e.
     * SITE_URL_<SLUG>) → the site's registered domain over https. New sites not
     * in the config map automatically get https://{domain}.
     */
    public function frontendBaseUrl(): string
    {
        $base = config('urls.sites.' . $this->slug, 'https://' . $this->domain);

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
    public function emailTemplateOrDefault(): Model
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
    public function promotionEmailOrDefault(): Model
    {
        return $this->promotionEmail()->firstOrCreate(
            [],
            SitePromotionEmail::defaultsFor($this),
        );
    }

    public function forum(): HasOne
    {
        return $this->hasOne(SiteForum::class);
    }

    /**
     * This site's forum-page configuration, created on first access.
     *
     * The row is created DISABLED — every column takes its migration default —
     * so merely opening the admin screen can never publish a page. Turning the
     * forum on stays an explicit act.
     */
    public function forumOrDefault(): Model
    {
        return $this->forum()->firstOrCreate([]);
    }

    /**
     * The forum settings as the PUBLIC side should read them, without writing.
     *
     * Deliberately NOT `forumOrDefault()`: that creates the row, and a public
     * GET must never insert. An unsaved model has null everywhere, and
     * `resolved()` turns null into the shipped defaults with `enabled` false —
     * so "no row" and "row that was never switched on" behave identically,
     * which is what they mean.
     *
     * @return array<string, mixed>
     */
    public function forumSettings(): array
    {
        return ($this->forum ?? new SiteForum())->resolved();
    }

    public function verifyEmail(): HasOne
    {
        return $this->hasOne(SiteVerifyEmail::class);
    }

    /**
     * The site's "verify your email" template, creating it with sensible
     * defaults on first access so every site always has one.
     */
    public function verifyEmailOrDefault(): Model
    {
        return $this->verifyEmail()->firstOrCreate(
            [],
            SiteVerifyEmail::defaultsFor($this),
        );
    }

    /**
     * The editorial byline, or null when this site has not configured one.
     *
     * Null when the switch is off OR when no name has been entered — a byline
     * with no person behind it is the exact failure this feature exists to
     * avoid, so both are "no byline" rather than one being a partial state that
     * renders something.
     *
     * @return array<string, mixed>|null
     */
    public function editorialAuthor(): ?array
    {
        if (! $this->byline_enabled || trim((string) $this->author_name) === '') {
            return null;
        }

        return [
            'name'        => $this->author_name,
            'role'        => $this->author_role,
            'bio'         => $this->author_bio,
            'avatar_path' => $this->author_avatar_path,
        ];
    }
}
