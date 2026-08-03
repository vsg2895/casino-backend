<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per subscriber-list import.
 *
 * The upload itself is handled by the request, but the parsing and writing run
 * in a queued job — a 200k-row file must not be processed inside an HTTP
 * request. This table is what makes that asynchronous run observable: the admin
 * polls a row to watch `imported` / `skipped` climb and to learn how it ended.
 *
 * It is also the audit trail: which admin imported which file into which site,
 * when, and with what result. Rows are kept after completion; `path` points at
 * the staged upload and is cleared once the job has consumed it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Who started it. Nullable so the row outlives the admin account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Original upload name (for display) + the staged path on the local
            // disk, which the job reads and then deletes.
            $table->string('filename');
            $table->string('path')->nullable();

            // Whether the imported addresses count as already verified.
            $table->boolean('verified')->default(false);

            // queued | processing | completed | failed
            $table->string('status', 20)->default('queued');

            // Running counters, updated batch by batch while the job works.
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);

            // Failure reason, surfaced to the admin instead of only the log.
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            // Admin listing: newest-first within a site.
            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_imports');
    }
};
