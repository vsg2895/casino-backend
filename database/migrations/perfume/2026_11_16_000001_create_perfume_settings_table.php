<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store's single settings row (see App\Perfume\Models\Setting::current()).
 * Every bracketed fact in the design ([PHONE], [HOURS], [AMOUNT]…) is a
 * nullable column here, seeded empty: the storefront hides what is not set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfume_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('site_name')->default('Big Glow Day');
            $table->string('domain')->default('bigglowday.com');
            $table->string('logo_path')->nullable();
            $table->string('accent_color', 7)->default('#D6F25C');

            // Contacts — [PHONE] [EMAIL] [HOURS]
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('hours')->nullable();
            $table->string('whatsapp_url')->nullable();
            $table->string('viber_url')->nullable();
            $table->string('telegram_url')->nullable();
            $table->string('instagram_url')->nullable();
            $table->string('facebook_url')->nullable();
            $table->string('tiktok_url')->nullable();
            $table->string('youtube_url')->nullable();

            // Top bar — "Sale — up to [X]% off"
            $table->string('topbar_sale_text')->nullable();
            $table->unsignedTinyInteger('sale_percent')->nullable();

            // Delivery / bonus / samples facts
            $table->string('delivery_yerevan_time')->nullable();
            $table->unsignedInteger('delivery_free_from')->nullable();
            $table->string('delivery_regions_time')->nullable();
            $table->unsignedTinyInteger('bonus_percent')->nullable();
            $table->unsignedTinyInteger('samples_max_per_order')->nullable();

            $table->json('payment_labels')->nullable();
            $table->text('map_embed_url')->nullable();

            // Home page texts
            $table->string('hero_eyebrow')->nullable();
            $table->string('hero_title')->nullable();
            $table->text('hero_text')->nullable();
            $table->string('hero_cta_primary_label')->nullable();
            $table->string('hero_cta_primary_url')->nullable();
            $table->string('hero_cta_secondary_label')->nullable();
            $table->string('hero_cta_secondary_url')->nullable();
            $table->string('notes_title')->nullable();
            $table->string('new_arrivals_title')->nullable();
            $table->string('samples_title')->nullable();
            $table->text('samples_text')->nullable();
            $table->json('samples_steps')->nullable();
            $table->string('samples_cta_label')->nullable();
            $table->string('samples_cta_url')->nullable();
            $table->string('brands_title')->nullable();
            $table->string('stores_title')->nullable();

            // Default SEO
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 500)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfume_settings');
    }
};
