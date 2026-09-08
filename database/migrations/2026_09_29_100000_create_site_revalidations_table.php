<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every attempt to tell a site its content changed.
 *
 * Until now `RevalidationService` swallowed failures into a log line, so the
 * admin panel had no way to know whether a save ever reached the front end.
 * `docs/admin-first.md` is explicit that this is not acceptable: "an editor must
 * be able to see that the site did not pick up their change."
 *
 * Every feature built on top of the admin-first contract has been asserting that
 * saving updates the site. This table is what makes that assertion checkable
 * rather than assumed.
 *
 * The denormalised columns on `sites` exist so the sites LIST can show cache
 * health without an aggregate per row — the history table answers "what
 * happened", the columns answer "is it healthy right now".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_revalidations')) {
            Schema::create('site_revalidations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained()->cascadeOnDelete();

                $table->json('tags');
                // 'success' | 'failed'
                $table->string('status', 10);
                // Null when the request never completed at all — a timeout or a
                // refused connection, as opposed to a response we did not like.
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->text('error')->nullable();
                $table->unsignedInteger('duration_ms')->default(0);
                // 'observer' (a content save) | 'manual' (the rebuild button)
                $table->string('triggered_by', 10)->default('observer');

                // No updated_at: an attempt is a fact about a moment and is
                // never edited.
                $table->timestamp('created_at')->nullable();

                $table->index(['site_id', 'created_at'], 'site_revalidations_site_created_index');
                // "show me recent failures across every site".
                $table->index(['status', 'created_at'], 'site_revalidations_status_created_index');
            });
        }

        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                if (! Schema::hasColumn('sites', 'last_revalidated_at')) {
                    $table->timestamp('last_revalidated_at')->nullable();
                }
                if (! Schema::hasColumn('sites', 'last_revalidation_status')) {
                    $table->string('last_revalidation_status', 10)->nullable();
                }
                if (! Schema::hasColumn('sites', 'last_revalidation_error')) {
                    $table->text('last_revalidation_error')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_revalidations');

        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table): void {
                foreach (['last_revalidated_at', 'last_revalidation_status', 'last_revalidation_error'] as $column) {
                    if (Schema::hasColumn('sites', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
