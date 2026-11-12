<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Casino;
use App\Models\CasinoSiteContent;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\SpecialOfferSiteContent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Admin writes and reads of per-site content overrides.
 *
 * The rules every caller gets, so no screen can skip one:
 *  - the default site never gets a row (its text IS the record's own fields);
 *  - a field equal to the default, or empty, is stored as NULL (inherit) — a
 *    copy of the default is not this site's own text, and storing it would
 *    hide later edits of the default;
 *  - a row left overriding nothing is deleted, not kept empty;
 *  - only the one (record, site) row is ever written.
 *
 * Cache invalidation happens in {@see \App\Observers\SiteContentObserver}.
 */
final class SiteContentEditor
{
    public static function defaultSiteSlug(): string
    {
        return (string) config('site_content.default_site_slug');
    }

    /** @return Collection<int, Site> Active sites that can carry overrides, by name. */
    public function editableSites(): Collection
    {
        return Site::query()
            ->where('active', true)
            ->where('slug', '!=', self::defaultSiteSlug())
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'domain']);
    }

    /**
     * The default content plus every editable site's row, for the edit screen.
     *
     * @return array<string, mixed>
     */
    public function overview(Casino|SpecialOffer $record): array
    {
        $rows = $record->siteContents()->get()->keyBy('site_id');
        $attached = $this->attachedSiteIds($record);
        $default = Site::query()->where('slug', self::defaultSiteSlug())->first(['id', 'name', 'slug', 'domain']);

        return [
            'default_site' => $default === null ? null : [
                'id' => $default->id, 'name' => $default->name, 'slug' => $default->slug, 'domain' => $default->domain,
            ],
            'default' => [
                'description'      => $record->description,
                'meta_title'       => $record->meta_title,
                'meta_description' => $record->meta_description,
                'noindex'          => (bool) $record->noindex,
            ],
            'sites' => $this->editableSites()->map(fn (Site $site): array => [
                'site_id'  => $site->id,
                'name'     => $site->name,
                'slug'     => $site->slug,
                'domain'   => $site->domain,
                'attached' => isset($attached[$site->id]),
                'content'  => self::row($rows->get($site->id)),
            ])->values()->all(),
        ];
    }

    /**
     * Save one site's override; returns the stored row, or null when nothing
     * is left to override and the row was removed.
     *
     * @param  array{description?: ?string, meta_title?: ?string, meta_description?: ?string, noindex?: ?bool}  $data
     */
    public function save(Casino|SpecialOffer $record, Site $site, array $data): CasinoSiteContent|SpecialOfferSiteContent|null
    {
        $this->assertEditable($site);

        $values = [
            'description'      => self::unlessDefault(CasinoSiteContent::cleanHtml($data['description'] ?? null), $record->description),
            'meta_title'       => self::unlessDefault(CasinoSiteContent::cleanText($data['meta_title'] ?? null), $record->meta_title),
            'meta_description' => self::unlessDefault(CasinoSiteContent::cleanText($data['meta_description'] ?? null), $record->meta_description),
            'noindex'          => $data['noindex'] ?? null,
        ];

        $row = $record->siteContents()->firstOrNew(['site_id' => $site->id]);
        $row->fill($values);

        if ($row->overridesNothing()) {
            if ($row->exists) {
                $row->delete();
            }

            return null;
        }

        $row->save();

        return $row;
    }

    public function clear(Casino|SpecialOffer $record, Site $site): void
    {
        $this->assertEditable($site);

        // Through the model, so the observer flushes the site's caches.
        $record->siteContents()->where('site_id', $site->id)->get()->each->delete();
    }

    /**
     * The field shape the admin reads, or null for "uses the default".
     *
     * @return array<string, mixed>|null
     */
    public static function row(CasinoSiteContent|SpecialOfferSiteContent|null $row): ?array
    {
        return $row === null ? null : [
            'description'      => $row->description,
            'meta_title'       => $row->meta_title,
            'meta_description' => $row->meta_description,
            'noindex'          => $row->noindex,
            'updated_at'       => $row->updated_at?->toISOString(),
        ];
    }

    private function assertEditable(Site $site): void
    {
        if ($site->slug === self::defaultSiteSlug()) {
            throw ValidationException::withMessages([
                'site' => 'The default site has no overrides — edit the default content instead.',
            ]);
        }
    }

    /** @return array<int, true> */
    private function attachedSiteIds(Casino|SpecialOffer $record): array
    {
        $casino = $record instanceof Casino ? $record : $record->casino;

        if ($casino === null) {
            return [];
        }

        return array_fill_keys(
            array_map('intval', $casino->sites()->wherePivot('active', true)->pluck('sites.id')->all()),
            true,
        );
    }

    private static function unlessDefault(?string $value, ?string $default): ?string
    {
        return $value !== null && $value === trim((string) $default) ? null : $value;
    }
}
