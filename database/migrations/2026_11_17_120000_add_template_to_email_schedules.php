<?php

declare(strict_types=1);

use App\Models\EmailSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a schedule choose WHICH email it sends.
 *
 * Every schedule sent the site's promotion template; the choice is now the
 * promotion, subscribe or verify template, or the operator's own HTML typed
 * into the schedule itself.
 *
 * `template` defaults to 'promotion' and is NOT NULL, so every existing row
 * keeps doing exactly what it does today without a data migration.
 *
 * Width 40 matches `schedule_send_histories.template` and
 * `warmup_sends.template` — the same keys travel into all three, and a column
 * narrower than the longest key is how `promotion_after_verification` (28 chars)
 * once hit SQLSTATE[22001] in production against a varchar(20).
 *
 * The four `custom_*` columns are filled only for the Custom HTML template and
 * are nullable for every other one; {@see EmailSchedule::customContent()} is
 * what reads them, and it returns null unless BOTH the subject and the body are
 * there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->string('template', 40)
                ->default(EmailSchedule::TEMPLATE_PROMOTION)
                ->after('name');

            // Custom HTML only. The From pair is per schedule because this
            // template has no stored site row to take one from — see the note
            // on EmailSchedule::customFromAddress() about domain alignment.
            $table->string('custom_from_name', 120)->nullable()->after('template');
            $table->string('custom_from_address')->nullable()->after('custom_from_name');
            $table->string('custom_subject')->nullable()->after('custom_from_address');
            $table->longText('custom_html')->nullable()->after('custom_subject');
        });
    }

    public function down(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->dropColumn([
                'template',
                'custom_from_name',
                'custom_from_address',
                'custom_subject',
                'custom_html',
            ]);
        });
    }
};
