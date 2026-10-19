<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A forum member.
 *
 * Authenticatable, but NOT the admin `User`: separate table, separate guard,
 * separate token lifetime. See the migration for why that separation is not
 * negotiable.
 */
class ForumUser extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_MUTED = 'muted';

    public const string STATUS_BANNED = 'banned';

    /**
     * Roles. Everyone registers as ROLE_USER.
     *
     * Separate from `status` on purpose — see the migration. `role` is what the
     * account is; `status` is what moderation has done to it.
     */
    public const string ROLE_USER = 'user';

    public const string ROLE_MODERATOR = 'moderator';

    /** @var list<string> */
    public const array ROLES = [self::ROLE_USER, self::ROLE_MODERATOR];

    /**
     * How many ACCEPTED posts a member needs before their writing publishes
     * without review. Configurable per the spec; three is the default.
     */
    /**
     * Retained but no longer consulted: every member post is moderated, so
     * there is no threshold to cross. Left in place because removing a public
     * constant is a breaking change for no benefit, and it documents what the
     * setting used to mean.
     *
     * @deprecated Every member post is pre-moderated — see isPreModerated().
     */
    public const string PREMODERATION_KEY = 'forum.premoderation_threshold';

    /** Accepted posts required before a member may post links. */
    public const int LINK_TRUST_THRESHOLD = 5;

    /**
     * `role` and `status` are ABSENT deliberately.
     *
     * Registration builds this array from request input, so a fillable `role`
     * would let anyone POST themselves a moderator account. Both are set with
     * forceFill from the admin path only.
     */
    protected $fillable = [
        'site_id',
        'display_name',
        'email',
        'password',
        'avatar_path',
    ];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'registration_ip'];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'marketing_consent_at' => 'datetime',
            'verification_promotion_sent_at' => 'datetime',
            'banned_until'         => 'datetime',
            'last_seen_at'         => 'datetime',
            'password'             => 'hashed',
            'trust_level'          => 'integer',
            'posts_count'          => 'integer',
            'approved_posts_count' => 'integer',
        ];
    }

    /**
     * This member's one-click opt-out token for the post-verification promotion.
     *
     * Minted on creation, like the newsletter tokens it mirrors, and never
     * regenerated: the link lives in a delivered email forever, and rotating it
     * would break the opt-out in every message already sent.
     *
     * A member has exactly ONE token because they can receive exactly one kind
     * of marketing email. A newsletter subscriber carries four, one per
     * template, which is what lets an unsubscribe record say which email it came
     * from — there is nothing to disambiguate here.
     */
    public function unsubscribeTokenFor(string $type = Unsubscribe::TYPE_PROMOTION_AFTER_VERIFICATION): string
    {
        return (string) $this->verification_promotion_unsubscribe_token;
    }

    /**
     * Resolve a member from an opt-out token, or null.
     *
     * The public unsubscribe endpoint tries the newsletter table first and falls
     * back to this. Without it the unsubscribe link in a member's promotion
     * email would resolve to nothing — a marketing email with a dead opt-out.
     */
    public static function findByUnsubscribeToken(string $token, ?int $siteId = null): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::query()
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->where('verification_promotion_unsubscribe_token', $token)
            ->first();
    }

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            if (trim((string) $user->slug) === '') {
                $user->slug = self::uniqueSlug((int) $user->site_id, (string) $user->display_name);
            }

            /*
             * The opt-out token, minted here so EVERY member has one before any
             * email can reference it. Generated at creation rather than at send
             * time because the link has to stay valid in a delivered message
             * forever — a token created per send would invalidate the previous
             * one, breaking the opt-out in every email already out there.
             */
            if (trim((string) $user->verification_promotion_unsubscribe_token) === '') {
                $user->verification_promotion_unsubscribe_token = Str::random(64);
            }
        });
    }

    /**
     * A slug that is free on this site.
     *
     * Display names are not unique and never will be — two people called "Alex"
     * both get an account. The slug is in a URL, so it has to be unique, and the
     * suffix is appended rather than the registration refused.
     */
    public static function uniqueSlug(int $siteId, string $displayName): string
    {
        $base = Str::slug($displayName) ?: 'member';
        $slug = $base;
        $n = 1;

        while (self::withTrashed()->where('site_id', $siteId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class);
    }

    /**
     * Whether this member may post at all.
     *
     * A mute expires; a ban does not. Reading `banned_until` alone would let a
     * permanent ban (null expiry) look like an expired mute, which is why
     * `status` is the authority and the timestamp only narrows it.
     */
    /** Whether this member carries any elevated role. */
    public function isStaff(): bool
    {
        return $this->role === self::ROLE_MODERATOR;
    }

    public function isSilenced(): bool
    {
        if ($this->status === self::STATUS_BANNED) {
            return true;
        }

        if ($this->status !== self::STATUS_MUTED) {
            return false;
        }

        return $this->banned_until === null || $this->banned_until->isFuture();
    }

    /**
     * Whether this member's next post skips the moderation queue.
     *
     * Reads `approved_posts_count`, never `posts_count`: posting three times is
     * not the bar, having three posts accepted is. A spammer whose every post is
     * rejected must never graduate on volume alone.
     */
    /**
     * Whether this member's posts are held for a moderator — always true.
     *
     * It used to be `approved_posts_count < threshold`, so a member who had
     * had 3 posts accepted then posted straight to the live forum. Every
     * member post is now reviewed before it appears, so this is true for
     * everybody, forever. See ForumPostService::decideStatus for the why.
     *
     * Kept as a method because it is what the API reports to the site and what
     * the site renders its "reviewed before it appears" notice from — the
     * notice must stay accurate, and now it is accurate for every member.
     */
    public function isPreModerated(): bool
    {
        return true;
    }

    /** Whether this member is trusted enough to include links. */
    public function mayPostLinks(): bool
    {
        return $this->approved_posts_count >= self::LINK_TRUST_THRESHOLD;
    }

    /** Members seen inside this window count as online. */
    public function scopeOnlineSince(Builder $query, Carbon $since): Builder
    {
        return $query->where('last_seen_at', '>=', $since);
    }
}
