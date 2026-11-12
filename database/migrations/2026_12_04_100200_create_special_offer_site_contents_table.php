<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One site's own SEO content for a shared special offer.
 *
 * Same contract as `casino_site_contents`: the offer's own columns are the
 * default (winpalack) content and the fallback; NULL here means "use it".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('special_offer_site_contents')) {
            return;
        }

        Schema::create('special_offer_site_contents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('special_offer_id')->constrained('special_offers')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->longText('description')->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 500)->nullable();
            $table->boolean('noindex')->nullable();
            $table->timestamps();

            $table->unique(['special_offer_id', 'site_id']);
            $table->index(['site_id', 'special_offer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_offer_site_contents');
    }
};
