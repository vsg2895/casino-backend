<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One visit to one Special Offer page.
 *
 * Recorded in two phases. Phase 1 is written from the request itself and is
 * the row that always exists; phase 2 adds what the page could establish about
 * its own connection afterwards. The split is deliberate: client-side
 * collection fails constantly — blockers, timeouts, a visitor who closes the
 * tab — and none of that may cost us the visit.
 *
 * WHAT THIS IS NOT: it is not an identity record. Every address here is either
 * what a proxy told us or what a page claimed, both of which can be wrong, and
 * `vpn_suspected` is a commercial classifier's opinion about an address range.
 * The admin screen labels them that way and so should any code reading this.
 */
class SpecialOfferVisit extends Model
{
    use HasFactory;

    protected $fillable = [
        'special_offer_id',
        'site_id',
        'visit_uuid',
        'primary_ip',
        'headers_raw',
        'user_agent',
        'referer',
        'page_url',
        'accept_language',
        'browser_timezone',
        'screen_size',
        'platform',
        'device_type',
        'browser',
        'os',
        'ip_mismatch',
        'vpn_suspected',
        'unique_ip_count',
        'consent',
    ];

    protected function casts(): array
    {
        return [
            'headers_raw'     => 'array',
            'ip_mismatch'     => 'boolean',
            'vpn_suspected'   => 'boolean',
            'unique_ip_count' => 'integer',
        ];
    }

    public function specialOffer(): BelongsTo
    {
        return $this->belongsTo(SpecialOffer::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function ips(): HasMany
    {
        return $this->hasMany(SpecialOfferVisitIp::class);
    }

    /** Public addresses only — the ones a geo lookup can say anything about. */
    public function publicIps(): HasMany
    {
        return $this->ips()->where('is_private', false)->where('is_mdns', false);
    }

    /**
     * Recompute the three derived flags from the rows that now exist.
     *
     * Called after EVERY insert rather than once at the end, because addresses
     * arrive from three places at different times: the request, the phase-2
     * beacon and the geo job. There is no moment at which the set is known to
     * be complete, so the flags are always a function of what is stored now.
     *
     * `ip_mismatch` compares only PUBLIC addresses, and only ones that could
     * plausibly be the same endpoint. A visitor legitimately has one IPv4 and
     * one IPv6 address at the same time, so disagreement between the two
     * families means nothing; the flag is about two different addresses within
     * one family, which is what a proxy in the path actually looks like.
     */
    public function refreshDerivedFlags(): void
    {
        $ips = $this->ips()->get();

        $public = $ips->filter(static fn (SpecialOfferVisitIp $row): bool => ! $row->is_private && ! $row->is_mdns);

        $byFamily = $public->groupBy(static fn (SpecialOfferVisitIp $row): int => $row->ip_version ?? 0);

        $mismatch = $byFamily->contains(
            static fn ($rows): bool => $rows->pluck('ip')->unique()->count() > 1
        );

        $flagged = $public->contains(
            static fn (SpecialOfferVisitIp $row): bool => $row->is_proxy || $row->is_vpn || $row->is_hosting
        );

        $this->forceFill([
            'unique_ip_count' => $ips->pluck('ip')->unique()->count(),
            'ip_mismatch'     => $mismatch,
            'vpn_suspected'   => $mismatch || $flagged,
        ])->save();
    }

    /**
     * The address the geo of the whole visit is reported from: whatever is in
     * `primary_ip`, as resolved by the request.
     */
    public function primaryIpRow(): ?SpecialOfferVisitIp
    {
        if ($this->primary_ip === null) {
            return null;
        }

        return $this->ips->firstWhere('ip', $this->primary_ip);
    }

    /**
     * Whether the browser's own timezone disagrees with the one its address
     * geolocates to — shown in the admin as a soft signal beside the IP flags.
     * Null when either side is unknown, which is not the same as "they agree".
     */
    public function timezoneMismatch(): ?bool
    {
        $ipTimezone = $this->primaryIpRow()?->timezone;

        if ($this->browser_timezone === null || $ipTimezone === null) {
            return null;
        }

        return $this->browser_timezone !== $ipTimezone;
    }

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }
}
