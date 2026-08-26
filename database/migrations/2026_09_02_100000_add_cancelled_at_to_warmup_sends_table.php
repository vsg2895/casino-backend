<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an in-flight warmup run be stopped.
 *
 * Until now a run could only end by finishing. If it wedged — the run lock
 * stranded by a failure between acquiring it and dispatching the fan-out, which
 * is exactly what SQLSTATE[22001] on `template` caused — the operator had no way
 * back except waiting out the 15-minute lock TTL.
 *
 * `cancelled_at` is the stop signal. Setting it does two things:
 *  - the fan-out stops dispatching further batches;
 *  - any batch already on the queue returns without sending.
 *
 * A FLAG RATHER THAN DELETING QUEUE ROWS, deliberately. Reaching into the queue
 * backend to purge jobs is driver-specific (this project runs redis, where there
 * is no row to delete), racy against a worker that has already reserved a job,
 * and silently destroys the retry bookkeeping. A flag the jobs themselves honour
 * works on every driver and leaves the audit intact — a cancelled run keeps its
 * history rows for the addresses it did reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warmup_sends', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('queued_count');
        });
    }

    public function down(): void
    {
        Schema::table('warmup_sends', function (Blueprint $table): void {
            $table->dropColumn('cancelled_at');
        });
    }
};
