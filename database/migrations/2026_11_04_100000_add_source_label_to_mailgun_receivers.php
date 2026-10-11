<?php

declare(strict_types=1);

use App\Support\Mail\ReceiverSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whose list a receiver came from, and which of those lists a credential may
 * mail.
 *
 * ── The column ──────────────────────────────────────────────────────────────
 *
 * `mailgun_receivers.source_label` takes the two values of
 * {@see ReceiverSource}: `our_clients` and `external_clients`. A closed set,
 * enforced by the database, because the field is a SEND-TIME FILTER — free text
 * would split an audience on a typo, and the half nobody notices is the half
 * that never gets mailed again.
 *
 * NOT NULL with a default, which is what makes the backfill free: MySQL writes
 * `our_clients` into every existing row as part of ADD COLUMN, so the 2,951
 * addresses already on the list are correctly described as ours without a
 * separate data pass. An UPDATE runs afterwards anyway, for the one case ADD
 * COLUMN cannot cover — a column left over as NULLable from an earlier run of
 * this migration.
 *
 * `enum` rather than a string + a CHECK: it is the narrowest type for two fixed
 * values, it is one byte on disk, and an invalid value is rejected by the
 * server rather than by whichever layer remembered to validate.
 *
 * ── Why not the existing `source` column ───────────────────────────────────
 *
 * `mailgun_receivers.source` is `import` / `manual` and says HOW the row was
 * created. This says WHOSE it is. Overloading one column with both would lose a
 * distinction the admin filter already uses.
 *
 * ── The credential side ─────────────────────────────────────────────────────
 *
 * `receiver_sources` on both credential tables lists the sources that
 * credential may mail, as JSON. NULL or `[]` means BOTH — the audience every
 * credential had before this column existed, so nothing changes for them on
 * deploy.
 */
return new class extends Migration
{
    /** Matches ReceiverSource::DEFAULT; repeated here because a migration is a historical record. */
    private const string DEFAULT_SOURCE = 'our_clients';

    public function up(): void
    {
        Schema::table('mailgun_receivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('mailgun_receivers', 'source_label')) {
                // ADD COLUMN with a default IS the backfill: every existing row
                // is written as `our_clients` by the same statement.
                $table->enum('source_label', ReceiverSource::values())
                    ->default(self::DEFAULT_SOURCE)
                    ->after('source');

                // Indexed because the send filters on it, over the whole table.
                $table->index('source_label', 'mailgun_receivers_source_label_idx');
            }
        });

        // Belt and braces, and idempotent: covers a column that already existed
        // as NULLable from an earlier shape of this migration.
        DB::table('mailgun_receivers')->whereNull('source_label')->update([
            'source_label' => self::DEFAULT_SOURCE,
        ]);

        // Carried on the staged import row so the queued job knows which source
        // to stamp: the file is uploaded in one request and processed in
        // another, and the choice belongs to the upload.
        Schema::table('mailgun_receiver_imports', function (Blueprint $table): void {
            if (! Schema::hasColumn('mailgun_receiver_imports', 'source_label')) {
                $table->enum('source_label', ReceiverSource::values())
                    ->default(self::DEFAULT_SOURCE)
                    ->after('filename');
            }
        });

        foreach (['mailgun_keys', 'smtp_credentials'] as $credentials) {
            Schema::table($credentials, function (Blueprint $table) use ($credentials): void {
                if (! Schema::hasColumn($credentials, 'receiver_sources')) {
                    $table->json('receiver_sources')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('mailgun_receivers', function (Blueprint $table): void {
            if (Schema::hasColumn('mailgun_receivers', 'source_label')) {
                $table->dropIndex('mailgun_receivers_source_label_idx');
                $table->dropColumn('source_label');
            }
        });

        Schema::table('mailgun_receiver_imports', function (Blueprint $table): void {
            if (Schema::hasColumn('mailgun_receiver_imports', 'source_label')) {
                $table->dropColumn('source_label');
            }
        });

        foreach (['mailgun_keys', 'smtp_credentials'] as $credentials) {
            Schema::table($credentials, function (Blueprint $table) use ($credentials): void {
                if (Schema::hasColumn($credentials, 'receiver_sources')) {
                    $table->dropColumn('receiver_sources');
                }
            });
        }
    }
};
