<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Physical shops — the "Visit a store" block ([STORE ADDRESS 1], [HOURS], Route). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('address');
            $table->string('hours')->nullable();
            $table->string('map_url')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_stores');
    }
};
