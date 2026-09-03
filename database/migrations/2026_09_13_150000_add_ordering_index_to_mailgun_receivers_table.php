<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the batch query filter AND order from one index.
 *
 * Measured on 100k rows, the selection query planned as:
 *
 *   -> Sort: created_at DESC, id DESC  (rows=49554)
 *     -> Index lookup using mailgun_receivers_selection_index
 *
 * The existing index covers the predicate (is_active, unsubscribed_at, …) but
 * not the ORDER BY, so MySQL filtered with the index and then filesorted ~50k
 * rows on every run. Leading with the same two equality columns and continuing
 * into (created_at, id) means the rows come out of the index already in
 * selection order, and the sort disappears.
 *
 * `last_sent_at` is deliberately NOT in this index: the cooldown predicate is an
 * OR (`last_sent_at IS NULL OR last_sent_at <= cutoff`), which cannot be an
 * index range, so including it would widen the key for no gain.
 *
 * Additive and guarded — adding an index changes no data and no behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailgun_receivers')) {
            return;
        }

        if ($this->hasIndex('mailgun_receivers', 'mailgun_receivers_active_order_index')) {
            return;
        }

        Schema::table('mailgun_receivers', function (Blueprint $table): void {
            $table->index(
                ['is_active', 'unsubscribed_at', 'created_at', 'id'],
                'mailgun_receivers_active_order_index',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('mailgun_receivers')) {
            return;
        }

        if (! $this->hasIndex('mailgun_receivers', 'mailgun_receivers_active_order_index')) {
            return;
        }

        Schema::table('mailgun_receivers', function (Blueprint $table): void {
            $table->dropIndex('mailgun_receivers_active_order_index');
        });
    }

    /** Driver-agnostic index probe, so this also runs under SQLite. */
    private function hasIndex(string $table, string $index): bool
    {
        return collect(
            Schema::getConnection()->getSchemaBuilder()->getIndexes($table),
        )->contains(fn (array $existing): bool => $existing['name'] === $index);
    }
};
