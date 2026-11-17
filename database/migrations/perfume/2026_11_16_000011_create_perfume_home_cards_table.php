<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Home page cards of two kinds: the three numbered hero tiles ("01 For her")
 * and the three service cards ("Only originals", the delivery card and
 * "Bonus points").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_home_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);   // hero_tile | service
            $table->string('number', 4)->nullable();
            $table->string('title');
            $table->text('text')->nullable();
            $table->string('link_label')->nullable();
            $table->string('url')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['kind', 'active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_home_cards');
    }
};
