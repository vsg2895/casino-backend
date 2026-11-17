<?php

declare(strict_types=1);

namespace Tests\Feature\Perfume;

use App\Models\User;
use App\Perfume\Models\Brand;
use App\Perfume\Models\Order;
use App\Perfume\Models\Product;
use App\Perfume\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerfumeOrderTest extends TestCase
{
    use RefreshDatabase;

    private const array HEADERS = ['X-Perfume-Key' => 'k'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfume.api_key' => 'k', 'cache.default' => 'array']);
    }

    private function variant(float $price = 129.99, bool $active = true): ProductVariant
    {
        $brand = Brand::create(['name' => 'Dior', 'slug' => 'dior']);
        $product = Product::create([
            'brand_id' => $brand->id, 'name' => 'Sauvage', 'slug' => 'dior-sauvage',
            'gender' => 'men', 'type' => 'edt', 'active' => $active,
        ]);

        return $product->variants()->create(['volume_ml' => 100, 'label' => '100 ml', 'price' => $price, 'stock' => 3]);
    }

    public function test_an_order_is_placed_and_its_lines_are_snapshotted(): void
    {
        $variant = $this->variant();

        $this->postJson('/api/perfume/public/orders', [
            'customer_name' => 'Ani',
            'phone'         => '+374 10 123456',
            'email'         => 'ani@example.com',
            'address'       => 'Springfield, IL',
            'items'         => [['variant_id' => $variant->id, 'qty' => 2]],
        ], self::HEADERS)
            ->assertStatus(201)
            ->assertJsonPath('data.items_count', 2)
            ->assertJsonPath('data.total', 259.98);

        $order = Order::with('items')->firstOrFail();

        $this->assertSame('new', $order->status);
        $this->assertStringStartsWith('BGD-', $order->reference);
        $this->assertSame('Dior', $order->items->first()->brand_name);
        $this->assertSame('100 ml', $order->items->first()->variant_label);
        $this->assertSame(129.99, $order->items->first()->price);
    }

    public function test_a_price_sent_by_the_browser_is_ignored(): void
    {
        $variant = $this->variant(129.99);

        $this->postJson('/api/perfume/public/orders', [
            'customer_name' => 'Ani',
            'phone'         => '1',
            // A hand-edited payload: the real price is read from the database.
            'items'         => [['variant_id' => $variant->id, 'qty' => 1, 'price' => 1]],
        ], self::HEADERS)->assertStatus(201)->assertJsonPath('data.total', 129.99);
    }

    public function test_name_phone_and_a_real_variant_are_required(): void
    {
        $this->postJson('/api/perfume/public/orders', [
            'items' => [['variant_id' => 999, 'qty' => 1]],
        ], self::HEADERS)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_name', 'phone', 'items.0.variant_id']);
    }

    public function test_a_basket_whose_product_went_away_is_refused(): void
    {
        $variant = $this->variant(129.99, active: false);

        $this->postJson('/api/perfume/public/orders', [
            'customer_name' => 'Ani',
            'phone'         => '1',
            'items'         => [['variant_id' => $variant->id, 'qty' => 1]],
        ], self::HEADERS)->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::count());
    }

    public function test_an_order_with_no_priced_line_keeps_a_null_total(): void
    {
        $brand = Brand::create(['name' => 'Dior', 'slug' => 'dior']);
        $product = Product::create(['brand_id' => $brand->id, 'name' => 'X', 'slug' => 'x', 'gender' => 'men', 'type' => 'edt']);
        $variant = $product->variants()->create(['volume_ml' => 100, 'label' => '100 ml', 'price' => null]);

        $this->postJson('/api/perfume/public/orders', [
            'customer_name' => 'Ani', 'phone' => '1',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ], self::HEADERS)->assertStatus(201)->assertJsonPath('data.total', null);
    }

    public function test_the_admin_lists_orders_and_moves_the_status_on(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/perfume/public/orders', [
            'customer_name' => 'Ani', 'phone' => '1',
            'items' => [['variant_id' => $variant->id, 'qty' => 1]],
        ], self::HEADERS);

        Sanctum::actingAs(User::factory()->create());
        $id = Order::firstOrFail()->id;

        $this->getJson('/api/perfume/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.customer_name', 'Ani')
            ->assertJsonCount(1, 'data.0.items');

        $this->patchJson("/api/perfume/admin/orders/{$id}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->patchJson("/api/perfume/admin/orders/{$id}", ['status' => 'posted'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_orders_need_the_site_key_and_the_admin_list_a_token(): void
    {
        $this->postJson('/api/perfume/public/orders', [])->assertStatus(401);
        $this->getJson('/api/perfume/admin/orders')->assertStatus(401);
    }
}
