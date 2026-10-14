<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether UniOne has actually been told where to send delivery events — and
 * whether any have arrived.
 *
 * ── The gap this closes ─────────────────────────────────────────────────────
 *
 * The webhook endpoint, its token gate, its MD5 verification, its de-duplication
 * and the status mapping were all built and all work. What was missing is the
 * one step that makes them run: NOTHING EVER CALLED `webhook/set.json`.
 * `UniOneClient::setWebhook()` existed with no caller, and the admin only
 * displayed the URL for an operator to paste into UniOne by hand.
 *
 * So the dashboard counts bounces while the receiver list stays clean, and there
 * is no way to tell from the admin whether that is because nothing bounced or
 * because UniOne was never told where to post. These three columns make the
 * difference visible:
 *
 *   webhook_registered_at   when registration last SUCCEEDED
 *   webhook_status          what UniOne answered, verbatim — including an error
 *   last_webhook_event_at   when an event last ARRIVED, which is the only proof
 *                           that registration took effect
 *
 * The last one matters most: a registration that returns success and then
 * delivers nothing is exactly the failure an operator cannot otherwise see.
 *
 * Purely additive — three nullable columns on a table with a handful of rows.
 * Nothing in the send path reads or writes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unione_api_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('unione_api_keys', 'webhook_registered_at')) {
                $table->timestamp('webhook_registered_at')->nullable()->after('last_verify_status');
            }

            if (! Schema::hasColumn('unione_api_keys', 'webhook_status')) {
                $table->string('webhook_status', 255)->nullable()->after('webhook_registered_at');
            }

            if (! Schema::hasColumn('unione_api_keys', 'last_webhook_event_at')) {
                $table->timestamp('last_webhook_event_at')->nullable()->after('webhook_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('unione_api_keys', function (Blueprint $table): void {
            foreach (['webhook_registered_at', 'webhook_status', 'last_webhook_event_at'] as $column) {
                if (Schema::hasColumn('unione_api_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
