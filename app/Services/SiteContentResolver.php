<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Casino;
use App\Models\CasinoSiteContent;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\SpecialOfferSiteContent;
use App\Support\ResolvedSiteContent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * THE single place a casino's or offer's content is resolved for one site.
 *
 * The record's own columns are the DEFAULT content — what the default site
 * publishes and every other site falls back to. A `*_site_contents` row
 * replaces them on one site, field by field:
 *
 *   description / meta_title / meta_description:
 *       this site's non-empty value → the record's own value.
 *   offer meta only, when both are empty:
 *       title       → "{offer title} – {casino name}"
 *       description → the resolved description as plain text, ~155 chars.
 *   noindex:
 *       record noindex                                → true (it always wins);
 *       this site's explicit Index / Noindex          → that;
 *       site.noindex_without_override and no own text → true;
 *       otherwise                                     → false.
 *
 * Rows are loaded per request and per site, in one query per batch: callers
 * that render a collection `prime*()` it first, so a listing costs one query
 * however many items it holds. A single unprimed record loads its own row.
 *
 * Registered as a SCOPED binding, so the memo never outlives the request.
 */
final class SiteContentResolver
{
    private const int DESCRIPTION_SNIPPET = 155;

    /** @var array<int, array<int, CasinoSiteContent|null>> site id → casino id → row */
    private array $casinoRows = [];

    /** @var array<int, array<int, SpecialOfferSiteContent|null>> site id → offer id → row */
    private array $offerRows = [];

    /**
     * Casino name per offer id, learned while priming offers nested in a
     * casino. Those offers do not carry their `casino` relation (loading it
     * would add a nested casino to the payload), and the title fallback must
     * not query once per card.
     *
     * @var array<int, string>
     */
    private array $casinoNameByOffer = [];

    /**
     * Load the override rows for every casino and offer in a resolved tree.
     *
     * Accepts casinos and offers mixed, and follows the relations a public
     * payload nests: a casino's (featured) offers, an offer's casino.
     *
     * @param  iterable<Model|null>  $models
     */
    public function prime(Site $site, iterable $models): void
    {
        $casinoIds = [];
        $offerIds = [];

        foreach ($models as $model) {
            $this->collect($model, $casinoIds, $offerIds);
        }

        $this->primeCasinoIds($site, array_keys($casinoIds));
        $this->primeOfferIds($site, array_keys($offerIds));
    }

    public function casino(Site $site, Casino $casino): ResolvedSiteContent
    {
        $row = $this->casinoRow($site, (int) $casino->getKey());

        $description = $this->pickHtml($row?->description, $casino->description);
        $title = $this->pickText($row?->meta_title, $casino->meta_title);
        $summary = $this->pickText($row?->meta_description, $casino->meta_description);

        return new ResolvedSiteContent(
            description: $description,
            metaTitle: $title,
            metaDescription: $summary,
            storedMetaTitle: $title,
            storedMetaDescription: $summary,
            noindex: $this->noindex($site, (bool) $casino->noindex, $row),
            updatedAt: $this->latest($casino->updated_at, $row?->updated_at),
        );
    }

    public function offer(Site $site, SpecialOffer $offer): ResolvedSiteContent
    {
        $row = $this->offerRow($site, (int) $offer->getKey());

        $description = $this->pickHtml($row?->description, $offer->description);
        $title = $this->pickText($row?->meta_title, $offer->meta_title);
        $summary = $this->pickText($row?->meta_description, $offer->meta_description);

        return new ResolvedSiteContent(
            description: $description,
            metaTitle: CasinoSiteContent::cleanText($title) ?? $this->offerTitleFallback($offer),
            metaDescription: CasinoSiteContent::cleanText($summary) ?? $this->snippet($description),
            storedMetaTitle: $title,
            storedMetaDescription: $summary,
            noindex: $this->noindex($site, (bool) $offer->noindex, $row),
            updatedAt: $this->latest($offer->updated_at, $row?->updated_at),
        );
    }

    /** This site's override row for a casino, or null. */
    public function casinoRow(Site $site, int $casinoId): ?CasinoSiteContent
    {
        if (! array_key_exists($casinoId, $this->casinoRows[$site->id] ?? [])) {
            $this->primeCasinoIds($site, [$casinoId]);
        }

        return $this->casinoRows[$site->id][$casinoId];
    }

    /** This site's override row for an offer, or null. */
    public function offerRow(Site $site, int $offerId): ?SpecialOfferSiteContent
    {
        if (! array_key_exists($offerId, $this->offerRows[$site->id] ?? [])) {
            $this->primeOfferIds($site, [$offerId]);
        }

        return $this->offerRows[$site->id][$offerId];
    }

    /**
     * @param  array<int, true>  $casinoIds
     * @param  array<int, true>  $offerIds
     */
    private function collect(?Model $model, array &$casinoIds, array &$offerIds): void
    {
        if ($model instanceof Casino) {
            $casinoIds[(int) $model->getKey()] = true;

            foreach (['specialOffers', 'featuredSpecialOffer'] as $relation) {
                if ($model->relationLoaded($relation)) {
                    $related = $model->getRelation($relation);

                    foreach ($related instanceof Model ? [$related] : ($related ?? []) as $offer) {
                        $offerIds[(int) $offer->getKey()] = true;
                        $this->casinoNameByOffer[(int) $offer->getKey()] = (string) $model->name;
                    }
                }
            }
        } elseif ($model instanceof SpecialOffer) {
            $offerIds[(int) $model->getKey()] = true;

            if ($model->relationLoaded('casino') && $model->casino !== null) {
                $casinoIds[(int) $model->casino->getKey()] = true;
            }
        }
    }

    /** @param list<int> $ids */
    private function primeCasinoIds(Site $site, array $ids): void
    {
        $missing = array_values(array_diff($ids, array_keys($this->casinoRows[$site->id] ?? [])));

        if ($missing === []) {
            return;
        }

        $rows = CasinoSiteContent::query()
            ->where('site_id', $site->id)
            ->whereIn('casino_id', $missing)
            ->get()
            ->keyBy('casino_id');

        foreach ($missing as $id) {
            $this->casinoRows[$site->id][$id] = $rows->get($id);
        }
    }

    /** @param list<int> $ids */
    private function primeOfferIds(Site $site, array $ids): void
    {
        $missing = array_values(array_diff($ids, array_keys($this->offerRows[$site->id] ?? [])));

        if ($missing === []) {
            return;
        }

        $rows = SpecialOfferSiteContent::query()
            ->where('site_id', $site->id)
            ->whereIn('special_offer_id', $missing)
            ->get()
            ->keyBy('special_offer_id');

        foreach ($missing as $id) {
            $this->offerRows[$site->id][$id] = $rows->get($id);
        }
    }

    private function noindex(Site $site, bool $global, CasinoSiteContent|SpecialOfferSiteContent|null $row): bool
    {
        if ($global) {
            return true;
        }

        if ($row?->noindex !== null) {
            return (bool) $row->noindex;
        }

        return (bool) $site->noindex_without_override
            && CasinoSiteContent::cleanHtml($row?->description) === null;
    }

    private function pickHtml(?string $own, ?string $default): ?string
    {
        return CasinoSiteContent::cleanHtml($own) ?? $default;
    }

    /**
     * The default comes back exactly as stored — not trimmed, not nulled — so a
     * site without an override serves byte-for-byte what it served before.
     */
    private function pickText(?string $own, ?string $default): ?string
    {
        return CasinoSiteContent::cleanText($own) ?? $default;
    }

    private function offerTitleFallback(SpecialOffer $offer): string
    {
        $name = trim((string) match (true) {
            $offer->relationLoaded('casino')                          => $offer->casino?->name,
            isset($this->casinoNameByOffer[(int) $offer->getKey()])   => $this->casinoNameByOffer[(int) $offer->getKey()],
            default                                                   => $offer->casino()->value('name'),
        });

        return $name === '' ? (string) $offer->title : $offer->title . ' – ' . $name;
    }

    /** The first ~155 characters of a rich text as plain text, cut on a word. */
    private function snippet(?string $html): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5)));

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) <= self::DESCRIPTION_SNIPPET) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::DESCRIPTION_SNIPPET + 1);
        $space = mb_strrpos($cut, ' ');

        $head = $space === false ? mb_substr($text, 0, self::DESCRIPTION_SNIPPET) : mb_substr($cut, 0, $space);

        // Multibyte-safe: rtrim() works on bytes and would split an en dash.
        return (string) preg_replace('/[\s,;:\-–]+$/u', '', $head) . '…';
    }

    private function latest(?CarbonInterface $own, ?CarbonInterface $override): ?CarbonInterface
    {
        if ($own === null || $override === null) {
            return $own ?? $override;
        }

        return $override->greaterThan($own) ? $override : $own;
    }
}
