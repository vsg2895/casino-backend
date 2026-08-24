<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Newsletter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'site_id',
        'email',
        'full_name',
        'verified',
        'unsubscribe_token',
        'promotion_unsubscribe_token',
        'verify_unsubscribe_token',
        'verification_promotion_unsubscribe_token',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            // When the subscriber clicked the verify link. NULL means they never
            // did (or predate the column). This is what the post-verification
            // promotion delay is measured from.
            'verified_at' => 'datetime',
            // Set by the atomic claim in SendVerificationPromotionJob, never by
            // mass assignment — hence cast but deliberately absent from
            // $fillable. Non-null means the post-verification promotion has been
            // claimed (and, unless a send failed and released it, delivered).
            'verification_promotion_sent_at' => 'datetime',
        ];
    }

    /** Unsubscribe tokens are secrets — never expose them in any response. */
    protected $hidden = [
        'unsubscribe_token',
        'promotion_unsubscribe_token',
        'verify_unsubscribe_token',
        'verification_promotion_unsubscribe_token',
    ];

    /**
     * Maps each unsubscribe category to the column holding that template's token.
     *
     * ONE token per template a subscriber can receive, so the opt-out it produces
     * can be attributed to the exact template it came from. Send-gating is global
     * (see {@see Unsubscribe::hasAny()}); this map is what makes detection precise.
     *
     * @return array<string, string>
     */
    public static function tokenColumns(): array
    {
        return [
            Unsubscribe::TYPE_SUBSCRIPTION                 => 'unsubscribe_token',
            Unsubscribe::TYPE_PROMOTION                    => 'promotion_unsubscribe_token',
            Unsubscribe::TYPE_VERIFY                       => 'verify_unsubscribe_token',
            Unsubscribe::TYPE_PROMOTION_AFTER_VERIFICATION => 'verification_promotion_unsubscribe_token',
        ];
    }

    protected static function booted(): void
    {
        // Every subscriber gets a stable, unguessable one-click unsubscribe token
        // per template (subscription, promotion, verify, promotion-after-
        // verification) so an opt-out can be attributed to the exact template it
        // came from, without ever exposing the address in the URL.
        static::creating(function (Newsletter $newsletter): void {
            foreach (self::tokenColumns() as $column) {
                if (empty($newsletter->{$column})) {
                    $newsletter->{$column} = self::generateUnsubscribeToken();
                }
            }
        });
    }

    public static function generateUnsubscribeToken(): string
    {
        return Str::random(64);
    }

    /** The opaque unsubscribe token for a given template (Unsubscribe::TYPE_*). */
    public function unsubscribeTokenFor(string $type): string
    {
        $column = self::tokenColumns()[$type] ?? 'unsubscribe_token';

        return (string) $this->{$column};
    }

    /**
     * The template an unsubscribe token belongs to, or null if it matches none.
     *
     * Compared with hash_equals against each template's token column, so the
     * exact template a subscriber unsubscribed through can be recorded. The
     * single definition used by both the one-click and the page-based endpoints.
     */
    public function unsubscribeTypeForToken(string $token): ?string
    {
        foreach (self::tokenColumns() as $type => $column) {
            $stored = (string) $this->{$column};
            if ($stored !== '' && hash_equals($stored, $token)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Resolve the subscriber a token belongs to, optionally scoped to a site.
     *
     * A single query ORs across every template's token column; tokens are
     * globally unique, so it resolves the subscriber (and, via
     * {@see unsubscribeTypeForToken()}, the template) unambiguously.
     */
    public static function findByUnsubscribeToken(string $token, ?int $siteId = null): ?self
    {
        return static::query()
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->where(function ($query) use ($token): void {
                foreach (self::tokenColumns() as $column) {
                    $query->orWhere($column, $token);
                }
            })
            ->first();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
