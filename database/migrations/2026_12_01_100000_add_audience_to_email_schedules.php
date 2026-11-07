<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schedules choose WHICH list they mail, and keep a send history of their own.
 *
 * `email_schedules` gains:
 *  - `audience` — newsletters | mailgun_receivers | sendgrid_receivers | warmup.
 *    Defaults to newsletters, so every existing schedule keeps its list.
 *  - `cooldown_days` — skip an address that received this site's promotion
 *    template within N days. Defaults to 1, the window the old 24-hour dedup
 *    already enforced.
 *
 * The sign-up-date audience is retired: the audience is now "the newest
 * `limit` addresses of the list, minus anyone inside the cooldown".
 * `date_filter` and `specific_date` are LEFT IN PLACE, unread — dropping them buys
 * nothing and would make this migration's rollback lossy. A legacy date-filter
 * schedule has no `limit`, and such a schedule sends NOTHING until an admin
 * gives it a count; nothing is guessed on its behalf (see
 * EmailSchedule::runBlocker()). No existing row is written by this migration.
 *
 * `schedule_send_histories` is one row per address per outcome per day: what
 * the Schedule tab of Email History lists, and what the cooldown reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_schedules', 'audience')) {
                $table->string('audience', 20)->default('newsletters')->after('name');
            }

            if (! Schema::hasColumn('email_schedules', 'cooldown_days')) {
                $table->unsignedSmallInteger('cooldown_days')->default(1)->after('limit');
            }
        });

        if (Schema::hasTable('schedule_send_histories')) {
            return;
        }

        Schema::create('schedule_send_histories', function (Blueprint $table): void {
            $table->id();

            // Nulled rather than cascaded: deleting a schedule must not erase
            // the record of what it sent. The name is copied for that reason.
            $table->foreignId('email_schedule_id')->nullable()->constrained('email_schedules')->nullOnDelete();
            $table->string('schedule_name', 120)->nullable();

            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();

            // Which list the address came from, and its row id there (no FK:
            // the id points into one of four tables).
            $table->string('audience', 20);
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('email', 255);

            // The template sent — the site's promotion email today.
            $table->string('template', 40);

            // How it was sent: the provider, the stored credential (id + its
            // name at send time, so a renamed or deleted key stays readable)
            // and the From address the message carried.
            $table->string('provider', 20);
            $table->unsignedBigInteger('credential_id')->nullable();
            $table->string('credential_label', 160);
            $table->string('from_address', 255)->nullable();

            // sent | failed | skipped
            $table->string('status', 10);
            $table->text('error')->nullable();

            $table->timestamp('sent_at');
            $table->date('sent_date');

            // One outcome per address, site, template and day — a retried batch
            // can never write a second `sent` row. Its prefix also serves the
            // cooldown lookup (site, template, email).
            $table->unique(
                ['site_id', 'template', 'email', 'sent_date', 'status'],
                'schedule_send_histories_daily_unique',
            );
            // The listing: newest first, optionally inside one schedule.
            $table->index('sent_at', 'schedule_send_histories_sent_at_index');
            $table->index(['email_schedule_id', 'sent_at'], 'schedule_send_histories_schedule_index');
            // Prefix search on the address.
            $table->index('email', 'schedule_send_histories_email_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_send_histories');

        Schema::table('email_schedules', function (Blueprint $table): void {
            foreach (['cooldown_days', 'audience'] as $column) {
                if (Schema::hasColumn('email_schedules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
