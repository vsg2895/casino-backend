<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled promotion campaigns.
 *
 * Each row is an admin-defined schedule that sends a site's promotion email
 * template to the subscribers whose `created_at` falls in a chosen window
 * (today / yesterday / last week … / a specific date), on a recurring cadence
 * (daily / weekly / monthly at a given time). A per-minute scheduler command
 * dispatches the campaign when a schedule is due.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120)->nullable();

            // Which subscribers: filter on newsletters.created_at.
            // today|yesterday|last_week|last_month|last_quarter|last_year|specific
            $table->string('date_filter', 20);
            $table->date('specific_date')->nullable(); // used when date_filter = specific

            // When to run. daily|weekly|monthly at HH:MM (server timezone).
            $table->string('frequency', 10);
            $table->string('time', 5); // 'HH:MM'
            $table->unsignedTinyInteger('day_of_week')->nullable();  // 0=Sun..6=Sat (weekly)
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1..31 (monthly)

            $table->boolean('active')->default(true);
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['active', 'time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_schedules');
    }
};
