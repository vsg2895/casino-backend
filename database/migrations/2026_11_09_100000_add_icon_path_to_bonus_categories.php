<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An icon for each bonus type.
 *
 * The Bonus menu and the home-page sections already name the types; the icon is
 * what turns that list into a row of chips a visitor can scan — the same
 * treatment `categories.logo_path` gives the casino categories, down to the
 * column shape, so one resolver on the front end serves both.
 *
 * A PATH, not the SVG itself: these are served from the public disk as their own
 * files and rendered through `<img src>`, where a script inside an SVG cannot
 * run. Storing the markup in the row would mean injecting it into the page
 * instead, which is a different and worse security posture for the same picture.
 *
 * NULLABLE, and no back-fill: a category with no icon renders its label alone,
 * which is exactly what every existing row does today. `php artisan migrate`
 * changes no data and breaks nothing that is already running.
 * {@see \Database\Seeders\BonusCategoryIconSeeder} is what fills them in, as a
 * separate and deliberate act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bonus_categories', function (Blueprint $table): void {
            $table->string('icon_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('bonus_categories', function (Blueprint $table): void {
            $table->dropColumn('icon_path');
        });
    }
};
