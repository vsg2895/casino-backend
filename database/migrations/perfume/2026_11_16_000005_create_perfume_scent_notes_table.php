<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_scent_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            // The home page's "Start from a note" chips.
            $table->boolean('featured_on_home')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->timestamps();

            $table->index(['featured_on_home', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_scent_notes');
    }
};
