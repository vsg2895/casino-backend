<?php

declare(strict_types=1);

use App\Models\Newsletter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a stable, unguessable unsubscribe token to every newsletter subscriber.
 * The token is the only secret needed to one-click unsubscribe, so it must be
 * unique and random. Existing rows are backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->string('unsubscribe_token', 64)->nullable()->unique()->after('email');
        });

        // Backfill existing subscribers (withTrashed so soft-deleted rows also get one).
        Newsletter::withTrashed()->whereNull('unsubscribe_token')->cursor()
            ->each(function (Newsletter $newsletter): void {
                $newsletter->forceFill(['unsubscribe_token' => Str::random(64)])->saveQuietly();
            });
    }

    public function down(): void
    {
        Schema::table('newsletters', function (Blueprint $table): void {
            $table->dropUnique(['unsubscribe_token']);
            $table->dropColumn('unsubscribe_token');
        });
    }
};
