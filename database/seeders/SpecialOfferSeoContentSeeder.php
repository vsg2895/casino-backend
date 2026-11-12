<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\SpecialOfferSiteContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * SEO content for the special offers live on production, one version per site
 * (see {@see SeoContentSeeder} for the mechanics and the safety rules).
 *
 * Every text states only what the offer publishes (value, wagering, minimum
 * deposit, cashout cap, timing) plus facts from the offering casino's verified
 * profile, each site from its own angle — claim steps, table-player verdict,
 * terms audit, wagering math, payout timing, fairness score, seven checks…
 * Where a published figure looked wrong (21bit's welcome bonus listed as "no
 * wagering, no deposit") it was left out rather than repeated.
 *
 * Offers follow their casino, so a per-site row is written where the offer's
 * casino is attached to the site.
 *
 * Not part of DatabaseSeeder. Run once after deploy:
 *   php artisan db:seed --class=SpecialOfferSeoContentSeeder --force
 */
class SpecialOfferSeoContentSeeder extends SeoContentSeeder
{
    protected function defaultFile(): string
    {
        return 'special_offer_seo_content.json';
    }

    protected function siteFile(): string
    {
        return 'special_offer_site_seo_content.json';
    }

    protected function backupName(): string
    {
        return 'special-offer-seo-content';
    }

    protected function records(array $slugs): Collection
    {
        return SpecialOffer::query()
            ->whereIn('slug', $slugs)
            ->with(['casino' => static fn ($query) => $query->select('id')->with(['sites' => static fn ($q) => $q->select('sites.id')])])
            ->get()
            ->keyBy('slug');
    }

    protected function siteIds(Model $record): array
    {
        return $record->casino?->sites->pluck('id')->map(static fn ($id): int => (int) $id)->all() ?? [];
    }

    protected function existingRows(Site $site): Collection
    {
        return SpecialOfferSiteContent::query()->where('site_id', $site->id)->get()->keyBy('special_offer_id');
    }

    protected function siteRow(Model $record, Site $site, Collection $existing): Model
    {
        return $existing->get($record->id)
            ?? new SpecialOfferSiteContent(['special_offer_id' => $record->id, 'site_id' => $site->id]);
    }

    protected function tags(): array
    {
        return ['special-offers', 'bonus'];
    }
}
