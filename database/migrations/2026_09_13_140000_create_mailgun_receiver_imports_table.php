<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress and outcome of one receiver spreadsheet import.
 *
 * Mirrors `newsletter_imports` so the admin polls it the same way — queue the
 * job, poll the row until `finished_at`, show real numbers rather than a
 * spinner. Kept separate from that table because the counters differ: this
 * import reports suppressed rows, which the newsletter import has no concept of.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailgun_receiver_imports')) {
            return;
        }

        Schema::create('mailgun_receiver_imports', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('filename', 255);
            $table->string('path', 255)->nullable();

            // Applied to every row that does not carry its own. Required by the
            // Form Request, so an import can never create provenance-less rows.
            $table->string('consent_source', 255);

            // queued | running | finished | failed
            $table->string('status', 20)->default('queued')->index();

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('suppressed')->default(0);
            $table->unsignedInteger('rejected')->default(0);

            // Newline-delimited rejected rows with reasons, offered to the admin
            // as a download. Capped by the job so a pathological file cannot
            // write an unbounded blob.
            $table->longText('rejected_rows')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailgun_receiver_imports');
    }
};
