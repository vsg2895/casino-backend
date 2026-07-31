<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores WHY a promotion send failed, alongside the attempt's `status`.
 *
 * Nullable and only populated for `failed` rows — `success` / `skipped` attempts
 * have no error to report. The value is the caught exception message, truncated
 * by the model so one pathological transport error can never bloat this
 * partitioned, permanently-retained table.
 *
 * Not indexed: it is diagnostic detail read alongside an already-filtered row
 * (by site / date / status), never a search key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotion_email_histories', function (Blueprint $table): void {
            $table->text('error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('promotion_email_histories', function (Blueprint $table): void {
            $table->dropColumn('error');
        });
    }
};
