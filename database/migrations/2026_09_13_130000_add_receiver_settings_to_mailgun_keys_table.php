<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-credential receiver targeting, held as columns on `mailgun_keys`.
 *
 * Columns rather than a separate rules table, deliberately:
 *  - the relationship is strictly 1:1 — a credential has exactly one rule, and
 *    the brief states the credential IS the sender selection, so there is no
 *    second rule to model;
 *  - the sending job reads the rule and the credential together on every run, so
 *    a separate table would add a join to the hot path for no gain;
 *  - the admin edits them in one modal, so one row means one atomic save.
 * A rules table would only earn its keep if a credential needed several named
 * rules, which the brief explicitly rules out.
 *
 * Every column is nullable or defaulted, so EXISTING credentials keep working
 * untouched: a row that has never opened the settings modal simply has sending
 * disabled and inherits the defaults below.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailgun_keys')) {
            return;
        }

        Schema::table('mailgun_keys', function (Blueprint $table): void {
            // Off by default. Adding the columns must never make an existing
            // credential start mailing people on the next scheduler tick.
            if (! Schema::hasColumn('mailgun_keys', 'send_enabled')) {
                $table->boolean('send_enabled')->default(false)->after('status');
            }

            // How many receivers one run may take. Mirrors warmup's
            // send_batch_size default of 100.
            if (! Schema::hasColumn('mailgun_keys', 'batch_size')) {
                $table->unsignedInteger('batch_size')->default(100)->after('send_enabled');
            }

            // 'newest' | 'oldest' — which end of the list a run works from.
            if (! Schema::hasColumn('mailgun_keys', 'selection_order')) {
                $table->string('selection_order', 10)->default('newest')->after('batch_size');
            }

            // Skip anyone this credential contacted within N days. Null disables
            // the filter, matching WarmupEmail::scopeNotContactedWithin().
            if (! Schema::hasColumn('mailgun_keys', 'cooldown_days')) {
                $table->unsignedSmallInteger('cooldown_days')->nullable()->default(1)->after('selection_order');
            }

            if (! Schema::hasColumn('mailgun_keys', 'only_active')) {
                $table->boolean('only_active')->default(true)->after('cooldown_days');
            }

            // Last time the dispatcher ran this credential — shown in the UI and
            // used to keep the scheduler from re-running a credential that a
            // concurrent worker already picked up.
            if (! Schema::hasColumn('mailgun_keys', 'last_run_at')) {
                $table->timestamp('last_run_at')->nullable()->after('only_active');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mailgun_keys')) {
            return;
        }

        // Drops only what up() added. The authentication columns and the sender
        // identity added by earlier migrations are untouched, so a rollback
        // cannot cost a working credential.
        Schema::table('mailgun_keys', function (Blueprint $table): void {
            foreach (['last_run_at', 'only_active', 'cooldown_days', 'selection_order', 'batch_size', 'send_enabled'] as $column) {
                if (Schema::hasColumn('mailgun_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
