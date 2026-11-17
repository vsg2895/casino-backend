<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The notes pyramid: which scent note sits in which layer of which product. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_product_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('perfume_products')->cascadeOnDelete();
            $table->foreignId('scent_note_id')->constrained('perfume_scent_notes')->cascadeOnDelete();
            $table->string('layer', 8);   // top | heart | base
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'scent_note_id', 'layer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_product_notes');
    }
};
