<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders placed from the storefront. There is NO payment: the visitor fills
 * in how to reach them and the shop confirms by phone, so the order carries
 * contact details and a status, never a transaction.
 *
 * The lines SNAPSHOT what was ordered (names, label, price). A product that
 * is later renamed, repriced or deleted must not rewrite an order that was
 * already placed — which is also why the product/variant keys are nullable
 * and set to null on delete rather than cascading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_orders', function (Blueprint $table): void {
            $table->id();
            // Short human code the shop quotes on the phone ("BGD-7KD4Q2").
            $table->string('reference', 16)->unique();
            $table->string('customer_name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('comment')->nullable();
            // new | confirmed | shipped | done | cancelled
            $table->string('status', 16)->default('new');
            // Sum of the priced lines. Null when nothing in the order had a price.
            $table->unsignedInteger('total')->nullable();
            $table->unsignedSmallInteger('items_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('perfume_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('perfume_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('perfume_products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('perfume_product_variants')->nullOnDelete();
            $table->string('brand_name')->nullable();
            $table->string('product_name');
            $table->string('product_slug')->nullable();
            $table->string('variant_label');
            $table->unsignedInteger('price')->nullable();
            $table->unsignedSmallInteger('qty')->default(1);
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_order_items');
        Schema::dropIfExists('perfume_orders');
    }
};
