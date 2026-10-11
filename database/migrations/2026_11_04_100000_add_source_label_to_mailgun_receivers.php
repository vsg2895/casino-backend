<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a receiver CAME FROM, as an editorial label — and which of those labels
 * a credential is allowed to mail.
 *
 * ── Why a second column and not the existing `source` ───────────────────────
 *
 * `mailgun_receivers.source` already exists and means something else: "import"
 * or "manual", i.e. HOW the row was created. It is a two-value enum the app
 * writes itself, and overloading it with free text would break the admin filter
 * that reads it and lose the distinction between a hand-added address and an
 * imported one.
 *
 * `source_label` is the operator's own word for a list: "Facebook ads",
 * "Partner list 3", "October webinar". Nullable, because every address already
 * on the list predates the field and belongs to no named source — and a NULL
 * there means "unlabelled", never "all".
 *
 * Indexed because it is a SELECTION predicate: the send picks its audience by
 * it, and that query runs over the whole receiver table.
 *
 * ── The credential side ─────────────────────────────────────────────────────
 *
 * `receiver_sources` on both credential tables is the list of labels that
 * credential may mail, as JSON. NULL or an empty array means "every source,
 * including unlabelled" — the behaviour every existing credential had before
 * this column existed, so nothing changes for them on deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailgun_receivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('mailgun_receivers', 'source_label')) {
                $table->string('source_label', 120)->nullable()->after('source');
                $table->index('source_label', 'mailgun_receivers_source_label_idx');
            }
        });

        // Carried on the staged import row so the queued job knows which label
        // to stamp: the file is uploaded in one request and processed in
        // another, and the choice belongs to the upload.
        Schema::table('mailgun_receiver_imports', function (Blueprint $table): void {
            if (! Schema::hasColumn('mailgun_receiver_imports', 'source_label')) {
                $table->string('source_label', 120)->nullable()->after('filename');
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
