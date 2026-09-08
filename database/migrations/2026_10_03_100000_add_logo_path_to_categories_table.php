<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A logo for each casino category.
 *
 * Stored as a PATH on the public disk, exactly like `casinos.image_path`, so the
 * front ends resolve it through the same `resolveImageUrl()` helper and nothing
 * new has to learn where uploads live.
 *
 * SVG, deliberately: these render at chip size (16-20px) and at card size in the
 * same page, and a raster asset would have to be uploaded twice or look soft at
 * one of them. It is also why the upload path for these does NOT go through
 * MediaUploadController's WebP conversion — see UploadCategoryLogoRequest.
 *
 * Nullable with no default. A category without a logo renders its name alone,
 * which is what every category does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('categories') && ! Schema::hasColumn('categories', 'logo_path')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->string('logo_path', 500)->nullable()->after('slug');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'logo_path')) {
            Schema::table('categories', function (Blueprint $table): void {
                $table->dropColumn('logo_path');
            });
        }
    }
};
