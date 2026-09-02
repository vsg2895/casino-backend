<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sender identity for a stored Mailgun credential, plus a uniqueness guarantee
 * on the label.
 *
 * Purely additive. `mailgun_keys` already carried everything Mailgun needs to
 * AUTHENTICATE — name, domain, api_key, region, status — so nothing here moves
 * or rewrites data, and no table is created or dropped. Two nullable columns
 * and (conditionally) one index; safe to run on a production database with rows
 * present, and safe to run twice.
 *
 * The two new columns are a FALLBACK sender only. Precedence at send time stays
 * exactly as it is today:
 *
 *   1. an explicit per-send override (HasSenderOverride::usingFromAddress)
 *   2. the SITE TEMPLATE's own from_email
 *   3. these columns — used only when 1 and 2 are both absent
 *
 * They can therefore never displace the per-site sender a template already
 * defines, which is what every existing send relies on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mailgun_keys')) {
            return;
        }

        Schema::table('mailgun_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('mailgun_keys', 'from_address')) {
                // Nullable: existing rows have no sender identity, and the site
                // template supplies one anyway. Null is the correct, working
                // state for a migrated row — not an incomplete one.
                $table->string('from_address', 255)->nullable()->after('region');
            }

            if (! Schema::hasColumn('mailgun_keys', 'from_name')) {
                $table->string('from_name', 120)->nullable()->after('from_address');
            }
        });

        $this->addUniqueNameIndexIfSafe();
    }

    public function down(): void
    {
        if (! Schema::hasTable('mailgun_keys')) {
            return;
        }

        // Drops only what up() added. The authentication columns this migration
        // never touched (name, domain, api_key, region, status) are left alone,
        // so rolling back cannot lose a working credential.
        Schema::table('mailgun_keys', function (Blueprint $table): void {
            if ($this->hasIndex('mailgun_keys', 'mailgun_keys_name_unique')) {
                $table->dropUnique('mailgun_keys_name_unique');
            }

            foreach (['from_name', 'from_address'] as $column) {
                if (Schema::hasColumn('mailgun_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Add the unique index on `name`, but ONLY when the existing rows allow it.
     *
     * A unique index on a column that already holds duplicates fails outright
     * and aborts the whole `migrate` run. Production content is not visible from
     * here, so the duplicates are checked for rather than assumed away: if any
     * exist the index is skipped and the migration still succeeds. The Form
     * Request enforces uniqueness on every write regardless, so new duplicates
     * cannot be introduced either way — and the index can be added later once
     * the existing clashes are renamed by hand.
     */
    private function addUniqueNameIndexIfSafe(): void
    {
        if ($this->hasIndex('mailgun_keys', 'mailgun_keys_name_unique')) {
            return;
        }

        $duplicates = DB::table('mailgun_keys')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            return;
        }

        Schema::table('mailgun_keys', function (Blueprint $table): void {
            $table->unique('name');
        });
    }

    /** Driver-agnostic index probe, so this migration also runs under SQLite. */
    private function hasIndex(string $table, string $index): bool
    {
        return collect(
            Schema::getConnection()->getSchemaBuilder()->getIndexes($table),
        )->contains(fn (array $existing): bool => $existing['name'] === $index);
    }
};
