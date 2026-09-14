<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The verdict that let a subscriber in, carried on the subscriber row.
 *
 * Denormalised from `email_validation_logs` on purpose: later sending logic
 * ("mail only addresses SendGrid scored above X") must be able to filter the
 * audience without joining a log table that is pruned on a schedule — the log
 * disappearing after 12 months must not change who receives mail.
 *
 * All three are NULL for every existing subscriber, and for anyone added by the
 * admin, an import, or while validation is switched off. NULL therefore means
 * "never validated", which is distinct from "validated and failed" — the latter
 * never produces a subscriber row at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('newsletters') || Schema::hasColumn('newsletters', 'validation_verdict')) {
            return;
        }

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->string('validation_verdict', 20)->nullable()->after('verified_at');
            $table->decimal('validation_score', 5, 4)->nullable()->after('validation_verdict');
            $table->timestamp('validated_at')->nullable()->after('validation_score');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('newsletters') || ! Schema::hasColumn('newsletters', 'validation_verdict')) {
            return;
        }

        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropColumn(['validation_verdict', 'validation_score', 'validated_at']);
        });
    }
};
