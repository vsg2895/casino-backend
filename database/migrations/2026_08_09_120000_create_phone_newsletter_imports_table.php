<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status record for one queued phone-list import.
 *
 * Same contract as `newsletter_imports`: the upload endpoint stages the file and
 * creates a row, then App\Jobs\ImportPhoneNewslettersJob owns it and moves it
 * through queued → processing → completed|failed while keeping the counters
 * current, so the admin panel polls a live figure instead of watching a spinner.
 *
 * Queued rather than synchronous for the same reason the subscriber import is:
 * a list of tens of thousands of numbers must not be parsed inside an HTTP
 * request, where it would occupy a php-fpm worker and eventually hit
 * max_execution_time.
 *
 * No site_id, unlike `newsletter_imports` — the phone list it writes into is not
 * site-scoped (see the create_newsletters_based_on_phone migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_newsletter_imports', function (Blueprint $table): void {
            $table->id();

            // Who uploaded it. Nullable so a deleted admin never orphans history.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('filename');
            // Relative path on the `local` disk. Nulled once the job consumes it,
            // so a finished import never keeps a spreadsheet on disk.
            $table->string('path')->nullable();

            $table->string('status', 12)->default('queued'); // queued|processing|completed|failed

            // Rows read, numbers written, numbers already on the list, and cells
            // that held something that was not a usable phone number.
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('invalid')->default(0);

            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_newsletter_imports');
    }
};
