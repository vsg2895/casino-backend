<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Resources\BrandResource;
use App\Perfume\Http\Resources\CategoryResource;
use App\Perfume\Http\Resources\HomeCardResource;
use App\Perfume\Http\Resources\NavItemResource;
use App\Perfume\Http\Resources\PageResource;
use App\Perfume\Http\Resources\PerfumerResource;
use App\Perfume\Http\Resources\ProductCardResource;
use App\Perfume\Http\Resources\ProductResource;
use App\Perfume\Http\Resources\ScentNoteResource;
use App\Perfume\Http\Resources\SettingResource;
use App\Perfume\Http\Resources\StoreResource;
use App\Perfume\Models\Brand;
use App\Perfume\Models\Category;
use App\Perfume\Models\HomeCard;
use App\Perfume\Models\NavItem;
use App\Perfume\Models\Page;
use App\Perfume\Models\Perfumer;
use App\Perfume\Models\Product;
use App\Perfume\Models\ScentNote;
use App\Perfume\Models\Setting;
use App\Perfume\Models\Store;
use App\Perfume\Services\CatalogQuery;
use App\Perfume\Support\PerfumeCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every read the storefront makes, behind `verify.perfume`. Each answer is
 * cached (PerfumeCache) as resolved arrays and wrapped in `{data: …}` so the
 * Next.js side reads one shape everywhere.
 *
 * Listings with filters cache per full query string: the filter space is
 * small and the TTL short, so a few hundred entries at most.
 */
class StorefrontController extends Controller
{
    public function __construct(private readonly CatalogQuery $catalog) {}

    public function settings(): JsonResponse
    {
        return $this->cached('settings', fn () => (new SettingResource(Setting::current()))->resolve());
    }

    /** Header categories, header links, footer columns, stores — the chrome every page shares. */
    public function nav(): JsonResponse
    {
        return $this->cached('nav', function (): array {
            $footer = NavItem::query()->where('location', 'footer')->where('active', true)->ordered()->get();

            return [
                'categories' => CategoryResource::collection(
                    Category::query()->active()->where('in_header', true)->ordered()->get()
                )->resolve(),
                'header'     => NavItemResource::collection(
                    NavItem::query()->where('location', 'header')->where('active', true)->ordered()->get()
                )->resolve(),
                'footer'     => $footer->groupBy(fn (NavItem $i) => (string) $i->group_label)
                    ->map(fn ($items, $label) => ['label' => $label, 'items' => NavItemResource::collection($items)->resolve()])
                    ->values()->all(),
                'stores'     => StoreResource::collection(Store::query()->where('active', true)->ordered()->get())->resolve(),
            ];
        });
    }

    public function home(): JsonResponse
    {
        return $this->cached('home', function (): array {
            $cards = HomeCard::query()->where('active', true)->ordered()->get();

            return [
                'hero_tiles'      => HomeCardResource::collection($cards->where('kind', 'hero_tile')->values())->resolve(),
                'services'        => HomeCardResource::collection($cards->where('kind', 'service')->values())->resolve(),
                'note_chips'      => ScentNoteResource::collection(
                    ScentNote::query()->where('featured_on_home', true)->ordered()->get()
                )->resolve(),
                'new_arrivals'    => ProductCardResource::collection(
                    Product::withStorefront()->visible()->where('is_new', true)->ordered()->limit(12)->get()
                )->resolve(),
                'featured_brands' => BrandResource::collection(
                    Brand::query()->active()->where('featured', true)->orderBy('name')->get()
                )->resolve(),
            ];
        });
    }

    public function categories(): JsonResponse
    {
        return $this->cached('categories', fn () => CategoryResource::collection(
            Category::query()->active()->withCount(['products' => fn (Builder $p) => $p->visible()])->ordered()->get()
        )->resolve());
    }

    /** The whole catalog (/catalog) with the filter rail. */
    public function products(Request $request): JsonResponse
    {
        return $this->cached('products:' . $this->qs($request), fn () => $this->catalog->run(Product::query()->visible(), $request));
    }

    public function category(Request $request, string $slug): JsonResponse
    {
        return $this->cached("category:{$slug}:" . $this->qs($request), function () use ($request, $slug): array {
            $category = Category::query()->active()->where('slug', $slug)->firstOrFail();

            return [
                'category' => (new CategoryResource($category))->resolve(),
                ...$this->catalog->run(CatalogQuery::forCategory($category), $request),
            ];
        });
    }

    public function product(string $slug): JsonResponse
    {
        return $this->cached("product:{$slug}", function () use ($slug): array {
            $product = Product::withStorefront()->visible()->where('slug', $slug)->firstOrFail();

            // "In the same family": same scent family, then the same brand,
            // then the same gender — until four are found.
            $related = collect();
            $passes = [
                fn (Builder $q) => $q->where('scent_family_id', $product->scent_family_id ?? -1),
                fn (Builder $q) => $q->where('brand_id', $product->brand_id),
                fn (Builder $q) => $q->where('gender', $product->gender),
            ];

            foreach ($passes as $pass) {
                if ($related->count() >= 4) {
                    break;
                }

                $related = $related->concat(
                    $pass(Product::withStorefront()->visible()
                        ->whereKeyNot($product->id)
                        ->whereNotIn('perfume_products.id', $related->pluck('id')))
                        ->ordered()->limit(4 - $related->count())->get()
                );
            }

            return [
                'product' => (new ProductResource($product))->resolve(),
                'related' => ProductCardResource::collection($related)->resolve(),
            ];
        });
    }

    public function brands(): JsonResponse
    {
        return $this->cached('brands', fn () => BrandResource::collection(
            Brand::query()->active()->withCount(['products' => fn (Builder $p) => $p->visible()])->orderBy('name')->get()
        )->resolve());
    }

    public function brand(Request $request, string $slug): JsonResponse
    {
        return $this->cached("brand:{$slug}:" . $this->qs($request), function () use ($request, $slug): array {
            $brand = Brand::query()->active()->where('slug', $slug)->firstOrFail();

            return [
                'brand' => (new BrandResource($brand))->resolve(),
                ...$this->catalog->run(Product::query()->visible()->where('brand_id', $brand->id), $request),
            ];
        });
    }

    public function notes(): JsonResponse
    {
        return $this->cached('notes', fn () => ScentNoteResource::collection(
            ScentNote::query()->withCount(['products' => fn (Builder $p) => $p->visible()])->orderBy('name')->get()
        )->resolve());
    }

    public function note(Request $request, string $slug): JsonResponse
    {
        return $this->cached("note:{$slug}:" . $this->qs($request), function () use ($request, $slug): array {
            $note = ScentNote::query()->where('slug', $slug)->firstOrFail();
            $base = Product::query()->visible()
                ->whereHas('notes', fn (Builder $n) => $n->where('scent_note_id', $note->id));

            return [
                'note' => (new ScentNoteResource($note))->resolve(),
                ...$this->catalog->run($base, $request),
            ];
        });
    }

    public function perfumers(): JsonResponse
    {
        return $this->cached('perfumers', fn () => PerfumerResource::collection(
            Perfumer::query()->withCount(['products' => fn (Builder $p) => $p->visible()])->orderBy('name')->get()
        )->resolve());
    }

    public function perfumer(Request $request, string $slug): JsonResponse
    {
        return $this->cached("perfumer:{$slug}:" . $this->qs($request), function () use ($request, $slug): array {
            $perfumer = Perfumer::query()->where('slug', $slug)->firstOrFail();

            return [
                'perfumer' => (new PerfumerResource($perfumer))->resolve(),
                ...$this->catalog->run(Product::query()->visible()->where('perfumer_id', $perfumer->id), $request),
            ];
        });
    }

    public function pages(): JsonResponse
    {
        return $this->cached('pages', fn () => PageResource::collection(
            Page::query()->where('active', true)->ordered()->get()
        )->resolve());
    }

    public function page(string $slug): JsonResponse
    {
        return $this->cached("page:{$slug}", fn () => (new PageResource(
            Page::query()->where('active', true)->where('slug', $slug)->firstOrFail()
        ))->resolve());
    }

    /** Slugs + last-modified for sitemap.xml. */
    public function sitemap(): JsonResponse
    {
        return $this->cached('sitemap', fn () => [
            'products'   => Product::query()->visible()->get(['slug', 'updated_at'])->map(fn ($p) => ['slug' => $p->slug, 'updated_at' => $p->updated_at?->toISOString()]),
            'categories' => Category::query()->active()->get(['slug', 'updated_at'])->map(fn ($c) => ['slug' => $c->slug, 'updated_at' => $c->updated_at?->toISOString()]),
            'brands'     => Brand::query()->active()->get(['slug', 'updated_at'])->map(fn ($b) => ['slug' => $b->slug, 'updated_at' => $b->updated_at?->toISOString()]),
            'notes'      => ScentNote::query()->get(['slug', 'updated_at'])->map(fn ($n) => ['slug' => $n->slug, 'updated_at' => $n->updated_at?->toISOString()]),
            'perfumers'  => Perfumer::query()->get(['slug', 'updated_at'])->map(fn ($n) => ['slug' => $n->slug, 'updated_at' => $n->updated_at?->toISOString()]),
            'pages'      => Page::query()->where('active', true)->get(['slug', 'updated_at'])->map(fn ($p) => ['slug' => $p->slug, 'updated_at' => $p->updated_at?->toISOString()]),
        ]);
    }

    private function cached(string $key, \Closure $callback): JsonResponse
    {
        return response()->json(['data' => PerfumeCache::remember($key, $callback)]);
    }

    /** Query string normalised (sorted keys) so `?a=1&b=2` and `?b=2&a=1` share a cache entry. */
    private function qs(Request $request): string
    {
        $query = $request->query();
        ksort($query);

        return md5(http_build_query($query));
    }
}
