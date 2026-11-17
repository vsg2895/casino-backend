<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fragrance. Prices and stock live on perfume_product_variants (one per
 * volume); images on perfume_product_images; the notes pyramid on
 * perfume_product_notes. `rating` / `reviews_count` are the design's
 * [RATING] · [N] reviews — facts the store does not have yet, so nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('perfume_brands')->cascadeOnDelete();
            $table->foreignId('perfumer_id')->nullable()->constrained('perfume_perfumers')->nullOnDelete();
            $table->foreignId('scent_family_id')->nullable()->constrained('perfume_scent_families')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sku')->nullable();
            $table->string('gender', 16);                 // women | men | unisex
            $table->string('type', 32);                   // edp | edt | parfum | …
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('country')->nullable();
            $table->text('description')->nullable();
            // Placeholder bottle colour when the product has no photo (design `tint`).
            $table->string('tint', 7)->nullable();
            $table->decimal('rating', 2, 1)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->boolean('is_new')->default(false);
            $table->boolean('is_on_sale')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamps();

            $table->index(['active', 'gender']);
            $table->index(['active', 'is_new', 'position']);
            $table->index('sku');
        });

        Schema::create('perfume_category_product', function (Blueprint $table): void {
            $table->foreignId('category_id')->constrained('perfume_categories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('perfume_products')->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_category_product');
        Schema::dropIfExists('perfume_products');
    }
};
