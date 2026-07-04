<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('casino_site', function (Blueprint $table) {
            $table->foreignId('casino_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('affiliate_url');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('featured')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['casino_id', 'site_id']);

            // Hottest public path: list a site's casinos filtered by active and
            // read straight in position order (no filesort), then PK-join casinos.
            $table->index(['site_id', 'active', 'position'], 'casino_site_site_active_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casino_site');
    }
};
