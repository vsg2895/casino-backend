<?php

declare(strict_types=1);

namespace Tests\Feature\Perfume;

use App\Models\User;
use App\Perfume\Models\Brand;
use App\Perfume\Models\Category;
use App\Perfume\Models\Product;
use App\Perfume\Models\ScentNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerfumeAdminProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Sanctum::actingAs(User::factory()->create());
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $brand = Brand::create(['name' => 'Dior', 'slug' => 'dior']);
        $men = Category::create(['name' => 'Men', 'slug' => 'men']);
        $note = ScentNote::create(['name' => 'Bergamot', 'slug' => 'bergamot']);

        return [
            'brand_id'     => $brand->id,
            'name'         => 'Sauvage',
            'slug'         => 'dior-sauvage',
            'gender'       => 'men',
            'type'         => 'edt',
            'category_ids' => [$men->id],
            'variants'     => [
                ['volume_ml' => 2, 'label' => '2 ml sample', 'is_sample' => true, 'stock' => 10],
                ['volume_ml' => 100, 'label' => '100 ml', 'price' => 129.99, 'stock' => 2],
            ],
            'images'       => [['path' => 'uploads/image/a.webp', 'alt' => 'Sauvage']],
            'notes'        => [
                ['scent_note_id' => $note->id, 'layer' => 'top'],
                ['scent_note_id' => $note->id, 'layer' => 'top'],   // duplicate: dropped, not an error
            ],
        ];
    }

    public function test_store_writes_the_product_with_its_nested_lists(): void
    {
        $res = $this->postJson('/api/perfume/admin/products', $this->payload())->assertStatus(201);

        $res->assertJsonPath('data.slug', 'dior-sauvage')
            ->assertJsonCount(2, 'data.variants')
            ->assertJsonCount(1, 'data.images')
            ->assertJsonCount(1, 'data.notes.top')
            ->assertJsonPath('data.categories.0.slug', 'men');

        $this->assertDatabaseCount('perfume_product_variants', 2);
        $this->assertDatabaseCount('perfume_product_notes', 1);
    }

    public function test_patch_without_nested_lists_leaves_them_alone(): void
    {
        $id = $this->postJson('/api/perfume/admin/products', $this->payload())->json('data.id');

        $this->patchJson("/api/perfume/admin/products/{$id}", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonCount(2, 'data.variants');

        // An explicit empty list DOES clear.
        $this->patchJson("/api/perfume/admin/products/{$id}", ['variants' => []])
            ->assertJsonCount(0, 'data.variants');
    }

    public function test_validation_rejects_bad_gender_type_and_duplicate_slug(): void
    {
        $payload = $this->payload();
        $this->postJson('/api/perfume/admin/products', $payload);

        $this->postJson('/api/perfume/admin/products', [...$payload, 'gender' => 'kids'])
            ->assertStatus(422)->assertJsonValidationErrors(['gender', 'slug']);
        $this->postJson('/api/perfume/admin/products', [...$payload, 'slug' => 'other', 'type' => 'oil'])
            ->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_delete_cascades_to_variants_images_and_notes(): void
    {
        $id = $this->postJson('/api/perfume/admin/products', $this->payload())->json('data.id');

        $this->deleteJson("/api/perfume/admin/products/{$id}")->assertStatus(204);

        $this->assertSame(0, Product::count());
        $this->assertDatabaseCount('perfume_product_variants', 0);
        $this->assertDatabaseCount('perfume_product_images', 0);
        $this->assertDatabaseCount('perfume_product_notes', 0);
        $this->assertDatabaseCount('perfume_category_product', 0);
    }
}
