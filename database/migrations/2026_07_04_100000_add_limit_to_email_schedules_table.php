<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an alternative "most recent N" audience to schedules:
 *  - `date_filter` becomes nullable.
 *  - `limit` (required when no date filter) caps the send to the newest N
 *    subscribers, ordered by created_at desc.
 *
 * Also adds a (site_id, created_at) index to newsletters so both the date-range
 * (whereBetween) and the top-N (order by created_at desc limit) recipient
 * queries stay index-covered — no full scans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->unsignedInteger('limit')->nullable()->after('specific_date');
            $table->string('date_filter', 20)->nullable()->change();
        });

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->index(['site_id', 'created_at'], 'newsletters_site_id_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropIndex('newsletters_site_id_created_at_index');
        });

        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->dropColumn('limit');
            $table->string('date_filter', 20)->nullable(false)->change();
        });
    }
};
