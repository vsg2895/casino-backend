<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casinos', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                   // "Title" in the UI
            $table->string('slug')->unique();
            $table->string('image_path', 500)->nullable();            // main image / logo (uploaded)
            $table->string('banner_image', 500)->nullable();          // banner image (uploaded)
            $table->string('bonuses')->nullable();                    // free text, e.g. "500$ + 180 Free Spins"
            $table->string('affiliate_url', 500)->nullable();         // "Link" — default affiliate URL
            $table->longText('description')->nullable();
            $table->unsignedTinyInteger('rating')->default(0);        // 0–5
            $table->unsignedInteger('sort_order')->default(0);        // "Order"
            $table->unsignedBigInteger('featured_special_offer_id')->nullable()->index();
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->boolean('active')->default(true);                 // "Visibility"
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casinos');
    }
};
