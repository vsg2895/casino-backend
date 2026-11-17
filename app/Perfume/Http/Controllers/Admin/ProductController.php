<?php

declare(strict_types=1);

namespace App\Perfume\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Perfume\Http\Requests\ProductRequest;
use App\Perfume\Http\Resources\ProductResource;
use App\Perfume\Models\Product;
use App\Perfume\Services\ProductWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function __construct(private readonly ProductWriter $writer) {}

    /** Paginated, searchable by name / brand / SKU, filterable by brand and gender. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search', ''));

        $products = Product::withStorefront()
            ->when($search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $w) use ($search): void {
                    $w->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($request->filled('brand_id'), fn (Builder $q) => $q->where('brand_id', (int) $request->query('brand_id')))
            ->when($request->filled('gender'), fn (Builder $q) => $q->where('gender', (string) $request->query('gender')))
            ->ordered()
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return ProductResource::collection($products);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->writer->create($request->validated());

        return (new ProductResource($this->load($product)))->response()->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($this->load($product));
    }

    public function update(ProductRequest $request, Product $product): ProductResource
    {
        $this->writer->update($product, $request->validated());

        return new ProductResource($this->load($product->fresh()));
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }

    private function load(Product $product): Product
    {
        return $product->load(['brand', 'perfumer', 'scentFamily', 'categories', 'variants', 'images', 'notes.scentNote']);
    }
}
