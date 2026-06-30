<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('casino_category', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('casino_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'casino_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_category');
        Schema::dropIfExists('categories');
    }
};
