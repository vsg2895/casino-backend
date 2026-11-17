<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a schedule may mail addresses that opted out of its site.
 *
 * **Default false, and that is the only safe default.** An opt-out is global
 * per site — any unsubscribe, through any template, stops all mail from that
 * site to that address ({@see \App\Models\Unsubscribe::hasAny()}) — and that is
 * what the law and every mailbox provider expect. The flag exists because the
 * operator asked for the choice; turning it on is a deliberate act with its own
 * warning in the admin, not a setting to reach for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->boolean('include_unsubscribed')->default(false)->after('cooldown_days');
        });
    }

    public function down(): void
    {
        Schema::table('email_schedules', function (Blueprint $table): void {
            $table->dropColumn('include_unsubscribed');
        });
    }
};
