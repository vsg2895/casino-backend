<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Custom HTML" warmup template: a subject and HTML body typed in the send
 * dialog instead of a site's stored template.
 *
 * ON THE RUN ROW, not in the queue payload. A run fans out into many batches,
 * and a batch that runs out of time re-queues the rest of its addresses; every
 * one of them must send the same message, so the content lives once on
 * `warmup_sends` and each batch reads it by `warmup_send_id`. Null for every
 * run that used a stored template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warmup_sends', function (Blueprint $table): void {
            if (! Schema::hasColumn('warmup_sends', 'custom_subject')) {
                $table->string('custom_subject', 255)->nullable()->after('template');
            }

            if (! Schema::hasColumn('warmup_sends', 'custom_html')) {
                $table->longText('custom_html')->nullable()->after('custom_subject');
            }
        });
    }

    public function down(): void
    {
        Schema::table('warmup_sends', function (Blueprint $table): void {
            $table->dropColumn(['custom_subject', 'custom_html']);
        });
    }
};
