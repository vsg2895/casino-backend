<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Index tuning for the four query shapes that still read more rows than they
 * return. Schema only — not one row of data is read, written or moved.
 *
 * ── Why these four, and nothing else ────────────────────────────────────────
 *
 * Every index was checked against an actual EXPLAIN rather than guessed at. The
 * schema is already well covered; what is below is the residue, and each entry
 * names the query that pays for it.
 *
 *  1. mailgun_receivers — the campaign audience.
 *     `EXPLAIN` on `deleted_at IS NULL AND unsubscribed_at IS NULL ORDER BY
 *     created_at, id` reports a table scan plus a sort: no index starts with
 *     either column. The two that look like they should
 *     (`..._active_order_index`, `..._selection_index`) both lead with
 *     `is_active`, and `MailgunReceiver::scopeSendable()` deliberately stopped
 *     filtering on it — "every receiver on the list is meant to be mailed" — so
 *     they have been unusable ever since, on the one table that takes
 *     thousand-row imports and a row update per message sent.
 *
 *  2. articles — the public news/guides feed.
 *     It currently rides `(site_id, published_at)` and then post-filters `type`
 *     and `active`. On a site whose scraper has filed a hundred unpublished
 *     drafts, that reads every one of them to return twenty. `(site_id, type,
 *     active, published_at)` makes it an exact range. The existing `(site_id,
 *     type, published_at)` STAYS: it is what the admin listing uses, and that
 *     one deliberately shows drafts.
 *
 *  3. special_offers — the Bonus category page (`/bonus/{slug}`).
 *     `EXPLAIN` shows an FK lookup on `bonus_category_id`, then a filter on
 *     `active` and a filesort on `sort_order`. The composite removes both.
 *
 *  4. email_validation_logs — `email-validation:prune`.
 *     `DELETE … WHERE created_at < ?` is a table scan: of the nine indexes on
 *     this table, not one starts with `created_at`. It is the only query that
 *     ever has to touch the whole table, and the table exists to grow.
 *
 * ── The drops ───────────────────────────────────────────────────────────────
 *
 * Four indexes are removed because they cannot be chosen by any query in the
 * codebase: two lead with a column nothing filters on (above), and two are
 * exact prefixes of a wider index on the same table, which MySQL will always
 * prefer. Every index is a write cost on INSERT, UPDATE and DELETE, and
 * `email_validation_logs` is written on every address check.
 *
 * Dropping an index removes no data and is instantly reversible — `down()`
 * rebuilds all four.
 *
 * ── Production ──────────────────────────────────────────────────────────────
 *
 * On MySQL every statement is issued with `ALGORITHM=INPLACE, LOCK=NONE`, so
 * the server builds or drops each index with reads AND writes still running on
 * the table. Stating it explicitly is the point: if a future MySQL could only
 * satisfy one of these by copying the table, the statement FAILS rather than
 * silently locking a live table for the duration. Other drivers (SQLite under
 * test) go through the schema builder, which has no such clauses.
 *
 * `php artisan migrate` is the whole deployment step.
 */
return new class extends Migration
{
    /**
     * Indexes to create: table => [name => columns].
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array ADD = [
        'mailgun_receivers' => [
            // Campaign selection: both NULL checks, then the batch order.
            'mg_receivers_sendable_order_idx' => ['deleted_at', 'unsubscribed_at', 'created_at', 'id'],
        ],
        'articles' => [
            // Article::scopeVisible() inside a site and a section.
            'articles_site_type_active_pub_idx' => ['site_id', 'type', 'active', 'published_at'],
        ],
        'special_offers' => [
            // One bonus category's visible offers, in their configured order.
            'special_offers_bonus_active_sort_idx' => ['bonus_category_id', 'active', 'sort_order'],
        ],
        'email_validation_logs' => [
            // The retention prune, which is the only whole-table query here.
            'evl_created_at_idx' => ['created_at'],
        ],
    ];

    /**
     * Indexes to remove: table => [names].
     *
     * @var array<string, list<string>>
     */
    private const array DROP = [
        // Lead with `is_active`, which no query filters on — see scopeSendable().
        'mailgun_receivers' => [
            'mailgun_receivers_active_order_index',
            'mailgun_receivers_selection_index',
        ],
        /*
         * The FK's own single-column index, now that the composite above leads
         * with the same column and serves the constraint.
         *
         * InnoDB usually removes this one by itself the moment the composite is
         * added — it created it implicitly for the constraint and drops it when
         * another index can do the job. Doing it here as well makes the end
         * state the SAME on every path, including a re-apply after a rollback,
         * where the index has become a user index and InnoDB leaves it alone.
         * It runs after the ADD block, so the constraint is never without an
         * index. down() puts it back before removing the composite.
         */
        'special_offers' => [
            'special_offers_bonus_category_id_foreign',
        ],
        // Exact prefixes of evl_outcome_created_idx / evl_verdict_created_idx.
        'email_validation_logs' => [
            'email_validation_logs_outcome_index',
            'email_validation_logs_verdict_index',
        ],
    ];

    /**
     * What `down()` puts back, so a rollback restores the schema exactly.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array RESTORE = [
        'mailgun_receivers' => [
            'mailgun_receivers_active_order_index' => ['is_active', 'unsubscribed_at', 'created_at', 'id'],
            'mailgun_receivers_selection_index'    => ['is_active', 'unsubscribed_at', 'last_sent_at'],
        ],
        'email_validation_logs' => [
            'email_validation_logs_outcome_index' => ['outcome'],
            'email_validation_logs_verdict_index' => ['verdict'],
        ],
    ];

    public function up(): void
    {
        foreach (self::ADD as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }

        foreach (self::DROP as $table => $names) {
            foreach ($names as $name) {
                $this->dropIndex($table, $name);
            }
        }
    }

    /**
     * Indexes a FOREIGN KEY needs, recreated before the composite that replaced
     * them is dropped.
     *
     * Found the hard way, and worth recording: when
     * `special_offers_bonus_active_sort_idx` was added, InnoDB removed the
     * single-column `special_offers_bonus_category_id_foreign` index it had
     * implicitly created for the constraint — a composite whose FIRST column is
     * the foreign key serves the constraint just as well, so the redundant one
     * went. Good behaviour, and it leaves the new index as the only thing
     * holding the FK up, so dropping it in `down()` fails with
     *
     *   1553  Cannot drop index …: needed in a foreign key constraint
     *
     * unless the single-column index is put back first. A constraint must never
     * be without an index, not even between two statements.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array FK_GUARD = [
        'special_offers' => [
            'special_offers_bonus_category_id_foreign' => ['bonus_category_id'],
        ],
    ];

    public function down(): void
    {
        foreach (self::RESTORE as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }

        // Before anything is dropped: give every foreign key its own index back.
        foreach (self::FK_GUARD as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex($table, $name, $columns);
            }
        }

        foreach (self::ADD as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                $this->dropIndex($table, $name);
            }
        }
    }

    /** @param  list<string>  $columns */
    private function addIndex(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        // Re-runnable: a half-finished migration can be run again without
        // tripping over the indexes it already created.
        if ($this->onMysql()) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD INDEX `%s` (%s), ALGORITHM=INPLACE, LOCK=NONE',
                $table,
                $name,
                collect($columns)->map(static fn (string $c): string => "`{$c}`")->implode(', '),
            ));

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
            $blueprint->index($columns, $name);
        });
    }

    private function dropIndex(string $table, string $name): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $name)) {
            return;
        }

        if ($this->onMysql()) {
            DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`, ALGORITHM=INPLACE, LOCK=NONE', $table, $name));

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name): void {
            $blueprint->dropIndex($name);
        });
    }

    /**
     * Driver-agnostic "does this index exist".
     *
     * Laravel's schema introspection reports indexes per table on every
     * supported driver, which keeps this migration runnable on the SQLite the
     * test suite uses as well as on production MySQL.
     */
    private function indexExists(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (strcasecmp((string) ($index['name'] ?? ''), $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private function onMysql(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
