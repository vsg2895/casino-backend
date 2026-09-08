<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-record SEO overrides: a canonical URL and a noindex switch.
 *
 * `meta_title` and `meta_description` already exist on these tables. What is
 * missing is the ability to (a) point a page's canonical somewhere else, which
 * is how duplicate content between our six domains gets resolved deliberately
 * rather than by Google guessing, and (b) keep a page out of the index at all.
 *
 * Both default to "no override": NULL canonical means the page is its own
 * canonical, and noindex defaults FALSE so this migration cannot accidentally
 * deindex anything that is currently ranking.
 *
 * Applied to all three content tables at once because the fields mean exactly
 * the same thing on each, and splitting it into three migrations would only
 * make the set harder to reason about later.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = ['casinos', 'special_offers', 'cms_pages'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'canonical_url')) {
                    // Absolute URL. Deliberately NOT validated as belonging to
                    // one of our domains: pointing a canonical at the operator's
                    // own page is a legitimate, if rare, editorial choice.
                    $blueprint->string('canonical_url', 500)->nullable();
                }

                if (! Schema::hasColumn($table, 'noindex')) {
                    $blueprint->boolean('noindex')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                foreach (['canonical_url', 'noindex'] as $column) {
                    if (Schema::hasColumn($table, $column)) {
                        $blueprint->dropColumn($column);
                    }
                }
            });
        }
    }
};
