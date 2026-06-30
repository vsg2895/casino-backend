<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_links', function (Blueprint $table): void {
            $table->id();
            // Per-site like newsletters (direct FK, not a many-to-many pivot).
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('platform');                       // e.g. facebook, twitter, instagram — maps to an icon
            $table->string('label')->nullable();              // optional handle / custom label
            $table->string('url');
            $table->unsignedInteger('sort_order')->default(0); // lower = shown first
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['site_id', 'active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_links');
    }
};
