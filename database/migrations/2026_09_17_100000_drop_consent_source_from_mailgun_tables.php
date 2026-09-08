<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops `consent_source` from the Mailgun receiver tables.
 *
 * The field recorded where each address came from. It is no longer captured on
 * either write path, so the column would only ever hold history — and a column
 * nothing writes and nothing reads is worse than no column, because the next
 * person to read the schema has to work out which it is.
 *
 * THIS MIGRATION DESTROYS DATA. `down()` re-adds the columns but cannot restore
 * what was in them: on `mailgun_receivers` the column comes back nullable and
 * empty, and on `mailgun_receiver_imports` — where it was NOT NULL — it comes
 * back with a default of '' so existing rows remain valid. Rolling back
 * therefore restores the shape, never the content.
 *
 * `consent_recorded_at` on `mailgun_receivers` is deliberately NOT touched: it
 * was not part of this change, and dropping a timestamp nobody asked about is
 * not something a migration should decide on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mailgun_receivers') && Schema::hasColumn('mailgun_receivers', 'consent_source')) {
            Schema::table('mailgun_receivers', function (Blueprint $table): void {
                $table->dropColumn('consent_source');
            });
        }

        if (Schema::hasTable('mailgun_receiver_imports') && Schema::hasColumn('mailgun_receiver_imports', 'consent_source')) {
            Schema::table('mailgun_receiver_imports', function (Blueprint $table): void {
                $table->dropColumn('consent_source');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mailgun_receivers') && ! Schema::hasColumn('mailgun_receivers', 'consent_source')) {
            Schema::table('mailgun_receivers', function (Blueprint $table): void {
                $table->string('consent_source', 255)->nullable()->after('source');
            });
        }

        if (Schema::hasTable('mailgun_receiver_imports') && ! Schema::hasColumn('mailgun_receiver_imports', 'consent_source')) {
            Schema::table('mailgun_receiver_imports', function (Blueprint $table): void {
                // Defaulted rather than plain NOT NULL: the original definition had
                // no default, and re-adding it that way would fail on any table
                // that already has rows.
                $table->string('consent_source', 255)->default('')->after('path');
            });
        }
    }
};
