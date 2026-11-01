<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Lets code that depends on a newer migration run against a database that has
 * not had it yet — degrading the feature, never the page.
 *
 * Code and migrations are deployed as two steps, and when they arrive in the
 * wrong order (or the migrate step is forgotten), a query that names a column
 * the table does not have yet takes the WHOLE endpoint down. That is exactly
 * what happened to every casino page in the network when the byline columns
 * were read before `2026_11_20_100200` had run: one optional line on the page
 * made the page itself a 404.
 *
 * Only for ADDITIVE features whose absence is a valid state (no byline, no
 * authors, nothing filtered by a flag that does not exist yet). Never for
 * anything a request must not proceed without.
 *
 * Answers are memoised per process: the schema does not change under a running
 * request, and `information_schema` is not something to query per row.
 */
final class SchemaGuard
{
    /** @var array<string, list<string>> table => column names */
    private static array $columns = [];

    public static function hasTable(string $table): bool
    {
        return self::columns($table) !== [];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        return in_array($column, self::columns($table), true);
    }

    /**
     * `$data` without the keys the table has no column for — so an admin form
     * that already sends a new field can still save the fields that exist.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function onlyExistingColumns(string $table, array $data): array
    {
        $columns = self::columns($table);

        return array_filter($data, static fn (string $key): bool => in_array($key, $columns, true), ARRAY_FILTER_USE_KEY);
    }

    /** Forget what was learned — for tests that migrate mid-process. */
    public static function flush(): void
    {
        self::$columns = [];
    }

    /** @return list<string> */
    private static function columns(string $table): array
    {
        return self::$columns[$table] ??= array_values(Schema::getColumnListing($table));
    }
}
