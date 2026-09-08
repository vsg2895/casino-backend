<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique intro copy for a casino's bonus sub-page.
 *
 * Without it, /casinos/{slug}/bonuses is the casino page's offer list with a
 * different heading — a thin duplicate that competes with its own parent. Forty
 * to sixty words of real copy is what makes it a page rather than a fragment.
 *
 * Nullable, and the route refuses to publish without it (see the front end), so
 * an unwritten intro means no page rather than an empty one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('casinos') || Schema::hasColumn('casinos', 'bonuses_intro')) {
            return;
        }

        Schema::table('casinos', function (Blueprint $table): void {
            $table->text('bonuses_intro')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('casinos') && Schema::hasColumn('casinos', 'bonuses_intro')) {
            Schema::table('casinos', function (Blueprint $table): void {
                $table->dropColumn('bonuses_intro');
            });
        }
    }
};
