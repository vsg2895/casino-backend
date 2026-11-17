<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per purchasable volume ("2 ml sample", "100 ml"). A price is
 * NULLABLE — the design's [PRICE] is a missing fact, and a variant without
 * one renders "Price on request" rather than a zero. Retyped to
 * decimal(10,2) in 2026_11_18_000001, when the shop moved to US dollars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('perfume_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('volume_ml');
            $table->string('label');
            $table->unsignedInteger('price')->nullable();
            $table->unsignedInteger('old_price')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_sample')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_product_variants');
    }
};
