<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Casino;
use App\Models\FacetConfig;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Casino listing facets: which exist, what values they have, and how they filter.
 *
 * ONE class for all three, deliberately. If "what values are offered" and "what
 * a value selects" were written separately they would drift, and the symptom is
 * the worst kind: a filter option that returns nothing when clicked. Here the
 * available values are derived from the same relations the filter queries.
 *
 * A facet with no values IS NOT OFFERED, regardless of configuration. That rule
 * is what makes this feature safe to ship before the operator profiles are
 * populated: today the site shows category and country, and licence, payment
 * method and provider appear on their own as the data arrives. Nothing has to be
 * switched on later, and no visitor is ever shown a control where every choice
 * returns an empty page.
 */
final class CasinoFacetService
{
    /**
     * Facets this site should render, each with its available values.
     *
     * Values carry a count so the front end can show "Curaçao (4)" and, more
     * importantly, so a value that would return nothing never reaches the page.
     *
     * @return list<array{facet: string, label: string, values: list<array{value: string, label: string, count: int}>}>
     */
    public function available(Site $site): array
    {
        $configured = FacetConfig::query()
            ->where('site_id', $site->id)
            ->where('active', true)
            ->orderBy('position')
            ->get()
            ->keyBy('facet');

        // No configuration means "offer the built-ins, in declaration order".
        $facets = $configured->isEmpty()
            ? FacetConfig::FACETS
            : $configured->keys()->all();

        $out = [];

        foreach ($facets as $facet) {
            $values = $this->valuesFor($site, $facet);

            // The rule that makes this safe to ship early.
            if ($values === []) {
                continue;
            }

            $out[] = [
                'facet'  => $facet,
                'label'  => $configured->get($facet)?->label
                    ?: FacetConfig::DEFAULT_LABELS[$facet],
                'values' => $values,
            ];
        }

        return $out;
    }

    /**
     * Apply the selected filters to a casino query.
     *
     * Every filter is an EXISTS against a relation rather than a join. Joins
     * against the pivots would multiply rows and quietly break the ordering the
     * listing depends on, and would also cost the
     * `casino_site_site_active_position_index` the base query relies on.
     *
     * @param  array<string, string>  $selected  facet => value
     */
    public function apply(Builder $query, array $selected): Builder
    {
        foreach ($selected as $facet => $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            match ($facet) {
                FacetConfig::FACET_CATEGORY => $query->whereHas(
                    'categories',
                    fn (Builder $q) => $q->where('categories.slug', $value),
                ),
                FacetConfig::FACET_COUNTRY => $query->whereHas(
                    'countries',
                    fn (Builder $q) => $q->where('countries.slug', $value)->where('countries.active', true),
                ),
                // The profile fields are JSON arrays of strings, so membership is
                // a JSON_CONTAINS rather than a column comparison.
                FacetConfig::FACET_LICENCE => $query->whereHas(
                    'detail',
                    fn (Builder $q) => $q->whereJsonContains('licences', $value),
                ),
                FacetConfig::FACET_PAYMENT_METHOD => $query->whereHas(
                    'detail',
                    fn (Builder $q) => $q->whereJsonContains('payment_methods', $value),
                ),
                FacetConfig::FACET_PROVIDER => $query->whereHas(
                    'detail',
                    fn (Builder $q) => $q->whereJsonContains('game_providers', $value),
                ),
                default => null,
            };
        }

        return $query;
    }

    /**
     * The values actually present among THIS site's casinos.
     *
     * Scoped to the site throughout: a licence held only by a casino on another
     * domain must not appear as an option here.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    private function valuesFor(Site $site, string $facet): array
    {
        $casinoIds = $this->casinoIds($site);

        if ($casinoIds === []) {
            return [];
        }

        return match ($facet) {
            FacetConfig::FACET_CATEGORY => $this->relationValues(
                $casinoIds, 'casino_category', 'category_id', 'categories', 'slug', 'name',
            ),
            FacetConfig::FACET_COUNTRY => $this->relationValues(
                $casinoIds, 'casino_country', 'country_id', 'countries', 'slug', 'name', true,
            ),
            FacetConfig::FACET_LICENCE       => $this->jsonValues($casinoIds, 'licences'),
            FacetConfig::FACET_PAYMENT_METHOD => $this->jsonValues($casinoIds, 'payment_methods'),
            FacetConfig::FACET_PROVIDER      => $this->jsonValues($casinoIds, 'game_providers'),
            default => [],
        };
    }

    /** @return list<int> */
    private function casinoIds(Site $site): array
    {
        return Casino::query()
            ->join('casino_site as pivot', 'casinos.id', '=', 'pivot.casino_id')
            ->where('pivot.site_id', $site->id)
            ->where('pivot.active', true)
            ->where('casinos.active', true)
            ->whereNull('casinos.deleted_at')
            ->pluck('casinos.id')
            ->all();
    }

    /**
     * Distinct values from a many-to-many relation, with counts.
     *
     * @param  list<int>  $casinoIds
     * @return list<array{value: string, label: string, count: int}>
     */
    private function relationValues(
        array $casinoIds,
        string $pivot,
        string $foreignKey,
        string $table,
        string $valueColumn,
        string $labelColumn,
        bool $activeOnly = false,
    ): array {
        $query = DB::table($pivot)
            ->join($table, $table . '.id', '=', $pivot . '.' . $foreignKey)
            ->whereIn($pivot . '.casino_id', $casinoIds)
            ->groupBy($table . '.' . $valueColumn, $table . '.' . $labelColumn)
            ->orderBy($table . '.' . $labelColumn)
            ->select([
                $table . '.' . $valueColumn . ' as value',
                $table . '.' . $labelColumn . ' as label',
                DB::raw('COUNT(DISTINCT ' . $pivot . '.casino_id) as count'),
            ]);

        if ($activeOnly) {
            $query->where($table . '.active', true);
        }

        return $query->get()
            ->map(static fn ($row): array => [
                'value' => (string) $row->value,
                'label' => (string) $row->label,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * Distinct entries across a JSON array column, with counts.
     *
     * Unnested in PHP rather than with JSON_TABLE: the row count here is the
     * number of casinos on one site — dozens — and JSON_TABLE would tie this to
     * MySQL 8 syntax for no measurable gain at this size.
     *
     * @param  list<int>  $casinoIds
     * @return list<array{value: string, label: string, count: int}>
     */
    private function jsonValues(array $casinoIds, string $column): array
    {
        $rows = DB::table('casino_details')
            ->whereIn('casino_id', $casinoIds)
            ->whereNotNull($column)
            ->pluck($column);

        $counts = [];

        foreach ($rows as $raw) {
            $decoded = json_decode((string) $raw, true);

            if (! is_array($decoded)) {
                continue;
            }

            // Unique per casino, so a casino listing "Visa" twice counts once.
            foreach (array_unique(array_filter(array_map('trim', $decoded))) as $value) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }

        ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(
            static fn (string $value, int $count): array => [
                'value' => $value,
                'label' => $value,
                'count' => $count,
            ],
            array_keys($counts),
            array_values($counts),
        );
    }
}
