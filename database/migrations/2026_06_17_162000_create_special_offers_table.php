<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('casino_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('image_path', 500)->nullable();
            $table->string('banner_image', 500)->nullable();
            $table->string('bonuses')->nullable();
            $table->string('affiliate_url', 500)->nullable();         // "Link"
            $table->longText('description')->nullable();
            $table->unsignedTinyInteger('rating')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_offers');
    }
};
