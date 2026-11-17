<?php

declare(strict_types=1);

namespace App\Perfume\Services;

use App\Perfume\Http\Resources\ProductCardResource;
use App\Perfume\Models\Brand;
use App\Perfume\Models\Category;
use App\Perfume\Models\Product;
use App\Perfume\Models\ProductVariant;
use App\Perfume\Models\ScentFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The catalog listing: the design's filter rail (in stock, on sale, sample
 * available, price, brand, type, volume, scent family, country), its sort
 * menu and pagination, over one base scope (everything, a category, a
 * brand or a note).
 *
 * Multi-value filters arrive comma-separated (`brand=dior,chanel`). Price
 * filters and sorts look at FULL-SIZE variants only — a 2 ml sample's
 * price would otherwise make every product "from $5".
 *
 * Facets are computed on the BASE scope (the category), not the filtered
 * result, so an option never disappears the moment it is ticked.
 */
class CatalogQuery
{
    public const array SORTS = ['popular', 'price_asc', 'price_desc', 'rating', 'newest'];

    /**
     * @return array{products: mixed, meta: array<string, int>, facets: array<string, mixed>, filters: array<string, mixed>}
     */
    public function run(Builder $base, Request $request): array
    {
        $filters = $this->filters($request);
        $query = (clone $base)->with(['brand', 'variants', 'images', 'scentFamily']);

        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort']);

        $page = $query->paginate((int) config('perfume.per_page', 24))->withQueryString();

        return [
            'products' => ProductCardResource::collection($page->getCollection())->resolve(),
            'meta'     => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
            'facets'   => $this->facets(clone $base),
            'filters'  => $filters,
        ];
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $csv = static fn (string $key): array => array_values(array_filter(
            array_map('trim', explode(',', (string) $request->query($key, ''))),
            static fn (string $v): bool => $v !== '',
        ));

        $sort = (string) $request->query('sort', 'popular');

        return [
            'in_stock'  => $request->boolean('in_stock'),
            'on_sale'   => $request->boolean('on_sale'),
            'sample'    => $request->boolean('sample'),
            'price_min' => $request->filled('price_min') ? max(0, (float) $request->query('price_min')) : null,
            'price_max' => $request->filled('price_max') ? max(0, (float) $request->query('price_max')) : null,
            'brand'     => $csv('brand'),
            'type'      => array_values(array_intersect($csv('type'), array_keys(Product::TYPES))),
            'volume'    => array_values(array_filter(array_map('intval', $csv('volume')))),
            'family'    => $csv('family'),
            'country'   => $csv('country'),
            'q'         => mb_substr(trim((string) $request->query('q', '')), 0, 100),
            'sort'      => in_array($sort, self::SORTS, true) ? $sort : 'popular',
            'page'      => max(1, (int) $request->query('page', 1)),
        ];
    }

    /** @param array<string, mixed> $f */
    private function applyFilters(Builder $q, array $f): void
    {
        // The header search: name, brand or a note, as its placeholder says.
        if ($f['q'] !== '') {
            $term = '%' . $f['q'] . '%';
            $q->where(function (Builder $w) use ($term): void {
                $w->where('perfume_products.name', 'like', $term)
                    ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', $term))
                    ->orWhereHas('notes.scentNote', fn (Builder $n) => $n->where('name', 'like', $term));
            });
        }

        // "In stock only" means a FULL bottle: a product whose only stock is
        // its 2 ml sample is not what the filter is for.
        if ($f['in_stock']) {
            $q->whereHas('variants', fn (Builder $v) => $v->where('is_sample', false)->where('stock', '>', 0));
        }

        if ($f['on_sale']) {
            $q->where('is_on_sale', true);
        }

        if ($f['sample']) {
            $q->whereHas('variants', fn (Builder $v) => $v->where('is_sample', true));
        }

        if ($f['price_min'] !== null || $f['price_max'] !== null) {
            $q->whereHas('variants', function (Builder $v) use ($f): void {
                $v->where('is_sample', false)->whereNotNull('price');

                if ($f['price_min'] !== null) {
                    $v->where('price', '>=', $f['price_min']);
                }

                if ($f['price_max'] !== null) {
                    $v->where('price', '<=', $f['price_max']);
                }
            });
        }

        if ($f['brand'] !== []) {
            $q->whereHas('brand', fn (Builder $b) => $b->whereIn('slug', $f['brand']));
        }

        if ($f['type'] !== []) {
            $q->whereIn('type', $f['type']);
        }

        if ($f['volume'] !== []) {
            $q->whereHas('variants', fn (Builder $v) => $v->where('is_sample', false)->whereIn('volume_ml', $f['volume']));
        }

        if ($f['family'] !== []) {
            $q->whereHas('scentFamily', fn (Builder $s) => $s->whereIn('slug', $f['family']));
        }

        if ($f['country'] !== []) {
            $q->whereIn('country', $f['country']);
        }
    }

    private function applySort(Builder $q, string $sort): void
    {
        $minPrice = fn (Builder $v) => $v->where('is_sample', false)->whereNotNull('price');

        match ($sort) {
            'price_asc'  => $q->withMin(['variants as min_price' => $minPrice], 'price')->orderByRaw('min_price IS NULL')->orderBy('min_price'),
            'price_desc' => $q->withMin(['variants as min_price' => $minPrice], 'price')->orderByRaw('min_price IS NULL')->orderByDesc('min_price'),
            'rating'     => $q->orderByRaw('rating IS NULL')->orderByDesc('rating'),
            'newest'     => $q->orderByDesc('is_new')->orderByDesc('perfume_products.created_at'),
            default      => $q->orderBy('perfume_products.position')->orderBy('perfume_products.id'),
        };
    }

    /** @return array<string, mixed> */
    private function facets(Builder $base): array
    {
        $ids = (clone $base)->select('perfume_products.id');

        $brands = Brand::query()->active()
            ->whereIn('id', (clone $base)->select('brand_id'))
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn (Brand $b) => ['label' => $b->name, 'value' => $b->slug])
            ->values();

        $types = (clone $base)->distinct()->pluck('type')
            ->filter(fn ($t) => isset(Product::TYPES[$t]))
            ->map(fn ($t) => ['label' => Product::TYPES[$t], 'value' => $t])
            ->values();

        $volumes = ProductVariant::query()->where('is_sample', false)
            ->whereIn('product_id', $ids)
            ->distinct()->orderBy('volume_ml')->pluck('volume_ml')
            ->map(fn ($ml) => ['label' => (string) $ml, 'value' => (string) $ml])
            ->values();

        $families = ScentFamily::query()
            ->whereIn('id', (clone $base)->whereNotNull('scent_family_id')->select('scent_family_id'))
            ->ordered()->get(['name', 'slug'])
            ->map(fn (ScentFamily $s) => ['label' => $s->name, 'value' => $s->slug])
            ->values();

        $countries = (clone $base)->whereNotNull('country')->distinct()->orderBy('country')->pluck('country')
            ->map(fn ($c) => ['label' => $c, 'value' => $c])
            ->values();

        $prices = ProductVariant::query()->where('is_sample', false)->whereNotNull('price')
            ->whereIn('product_id', $ids)
            ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
            ->first();

        return [
            'brands'    => $brands,
            'types'     => $types,
            'volumes'   => $volumes,
            'families'  => $families,
            'countries' => $countries,
            'price'     => [
                'min' => $prices?->min_price !== null ? (float) $prices->min_price : null,
                'max' => $prices?->max_price !== null ? (float) $prices->max_price : null,
            ],
        ];
    }

    /** Base scope for a category listing. */
    public static function forCategory(Category $category): Builder
    {
        return Product::query()->visible()
            ->whereHas('categories', fn (Builder $c) => $c->where('perfume_categories.id', $category->id));
    }
}
