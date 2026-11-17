<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog sections: the header's Women / Men / Niche / Unisex / Gift sets /
 * Samples… A product belongs to any number of them (perfume_category_product).
 * `description` is the "[CATEGORY DESCRIPTION]" block under the listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('in_header')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamps();

            $table->index(['active', 'in_header', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_categories');
    }
};
