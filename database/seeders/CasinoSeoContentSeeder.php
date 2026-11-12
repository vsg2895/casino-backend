<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Casino;
use App\Models\CasinoSiteContent;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * SEO content for the casinos live on production, one version per site
 * (see {@see SeoContentSeeder} for the mechanics and the safety rules).
 *
 * Every text states only facts from that casino's verified operator profile
 * (licence, operator, banking, limits, payout times, providers, support,
 * safer-play tools), each site from its own editorial angle — payout timing,
 * licence audit, bonus terms, slots, table play… Bonus amounts and star
 * ratings are left out on purpose: both change in the admin, and a text that
 * quotes them goes stale silently.
 *
 * A per-site row is written where the casino is attached to the site.
 *
 * Not part of DatabaseSeeder. Run once after deploy:
 *   php artisan db:seed --class=CasinoSeoContentSeeder --force
 */
class CasinoSeoContentSeeder extends SeoContentSeeder
{
    protected function defaultFile(): string
    {
        return 'casino_seo_content.json';
    }

    protected function siteFile(): string
    {
        return 'casino_site_seo_content.json';
    }

    protected function backupName(): string
    {
        return 'casino-seo-content';
    }

    protected function records(array $slugs): Collection
    {
        return Casino::query()
            ->whereIn('slug', $slugs)
            ->with(['sites' => static fn ($query) => $query->select('sites.id')])
            ->get()
            ->keyBy('slug');
    }

    protected function siteIds(Model $record): array
    {
        return $record->sites->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    protected function existingRows(Site $site): Collection
    {
        return CasinoSiteContent::query()->where('site_id', $site->id)->get()->keyBy('casino_id');
    }

    protected function siteRow(Model $record, Site $site, Collection $existing): Model
    {
        return $existing->get($record->id)
            ?? new CasinoSiteContent(['casino_id' => $record->id, 'site_id' => $site->id]);
    }

    protected function tags(): array
    {
        return ['casinos'];
    }
}
