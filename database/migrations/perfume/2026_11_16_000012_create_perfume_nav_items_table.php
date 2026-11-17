<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Header menu (location=header) and footer link columns (location=footer, grouped by `group_label`). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_nav_items', function (Blueprint $table): void {
            $table->id();
            $table->string('location', 16);   // header | footer
            $table->string('group_label')->nullable();
            $table->string('label');
            $table->string('url');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['location', 'active', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_nav_items');
    }
};
