<?php

declare(strict_types=1);

namespace Tests\Feature\Perfume;

use App\Perfume\Models\Brand;
use App\Perfume\Models\Category;
use App\Perfume\Models\Product;
use App\Perfume\Models\ScentFamily;
use App\Perfume\Models\ScentNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerfumeStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private const array HEADERS = ['X-Perfume-Key' => 'k'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfume.api_key' => 'k', 'cache.default' => 'array']);
    }

    /** @return array{Category, Product, Product} */
    private function catalog(): array
    {
        $dior = Brand::create(['name' => 'Dior', 'slug' => 'dior', 'country' => 'France']);
        $chanel = Brand::create(['name' => 'Chanel', 'slug' => 'chanel', 'country' => 'France']);
        $men = Category::create(['name' => 'Men', 'slug' => 'men']);
        $woody = ScentFamily::create(['name' => 'Woody', 'slug' => 'woody']);

        $sauvage = Product::create([
            'brand_id' => $dior->id, 'scent_family_id' => $woody->id, 'name' => 'Sauvage', 'slug' => 'dior-sauvage',
            'gender' => 'men', 'type' => 'edt', 'country' => 'France', 'position' => 1,
        ]);
        $sauvage->categories()->sync([$men->id]);
        $sauvage->variants()->create(['volume_ml' => 2, 'label' => '2 ml sample', 'is_sample' => true, 'price' => 6.5, 'stock' => 5]);
        $sauvage->variants()->create(['volume_ml' => 100, 'label' => '100 ml', 'price' => 129.99, 'stock' => 0]);

        $bleu = Product::create([
            'brand_id' => $chanel->id, 'name' => 'Bleu de Chanel', 'slug' => 'bleu-de-chanel',
            'gender' => 'men', 'type' => 'edp', 'country' => 'France', 'position' => 2,
        ]);
        $bleu->categories()->sync([$men->id]);
        $bleu->variants()->create(['volume_ml' => 100, 'label' => '100 ml', 'price' => 99.5, 'stock' => 3]);

        return [$men, $sauvage, $bleu];
    }

    public function test_category_listing_returns_cards_facets_and_meta(): void
    {
        $this->catalog();

        $res = $this->getJson('/api/perfume/public/categories/men', self::HEADERS)->assertOk();

        $res->assertJsonPath('data.category.slug', 'men')
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.products.0.slug', 'dior-sauvage')
            ->assertJsonPath('data.products.0.meta', 'Eau de toilette · 100 ml')
            ->assertJsonPath('data.products.0.price', 129.99)   // the sample's $6.50 must not win
            ->assertJsonPath('data.products.0.has_sample', true)
            ->assertJsonPath('data.facets.price.min', 99.5)
            ->assertJsonPath('data.facets.price.max', 129.99)
            ->assertJsonCount(2, 'data.facets.brands');
    }

    public function test_filters_and_sort_narrow_the_listing(): void
    {
        $this->catalog();

        $this->getJson('/api/perfume/public/categories/men?in_stock=1', self::HEADERS)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.products.0.slug', 'bleu-de-chanel');

        $this->getJson('/api/perfume/public/categories/men?brand=dior', self::HEADERS)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.products.0.slug', 'dior-sauvage');

        $this->getJson('/api/perfume/public/categories/men?sort=price_asc', self::HEADERS)
            ->assertJsonPath('data.products.0.slug', 'bleu-de-chanel')
            ->assertJsonPath('data.filters.sort', 'price_asc');

        $this->getJson('/api/perfume/public/categories/men?price_min=110', self::HEADERS)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.products.0.slug', 'dior-sauvage');

        // A sample's price never satisfies a price filter.
        $this->getJson('/api/perfume/public/categories/men?price_max=10', self::HEADERS)
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_product_page_carries_notes_variants_and_related_by_gender(): void
    {
        [, $sauvage] = $this->catalog();
        $bergamot = ScentNote::create(['name' => 'Bergamot', 'slug' => 'bergamot']);
        $sauvage->notes()->create(['scent_note_id' => $bergamot->id, 'layer' => 'top']);

        $this->getJson('/api/perfume/public/products/dior-sauvage', self::HEADERS)
            ->assertOk()
            ->assertJsonPath('data.product.brand.name', 'Dior')
            ->assertJsonPath('data.product.notes.top.0.name', 'Bergamot')
            ->assertJsonCount(2, 'data.product.variants')
            ->assertJsonPath('data.related.0.slug', 'bleu-de-chanel');
    }

    public function test_inactive_product_and_unknown_slug_are_404(): void
    {
        [, $sauvage] = $this->catalog();
        $sauvage->update(['active' => false]);

        $this->getJson('/api/perfume/public/products/dior-sauvage', self::HEADERS)->assertStatus(404);
        $this->getJson('/api/perfume/public/products/nope', self::HEADERS)->assertStatus(404);
        $this->getJson('/api/perfume/public/categories/men', self::HEADERS)->assertJsonPath('data.meta.total', 1);
    }

    public function test_sitemap_lists_only_visible_slugs(): void
    {
        [, $sauvage] = $this->catalog();
        $sauvage->update(['active' => false]);

        $this->getJson('/api/perfume/public/sitemap', self::HEADERS)
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.slug', 'bleu-de-chanel')
            ->assertJsonCount(1, 'data.categories');
    }
}
