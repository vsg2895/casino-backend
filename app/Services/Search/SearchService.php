<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\SearchIndexEntry;
use App\Models\Site;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every search query in the application.
 *
 * DRIVER BOUNDARY. All matching lives behind this one class, so swapping MySQL
 * FULLTEXT for Meilisearch/Typesense later is a single-class change and touches
 * neither the controller nor the indexer. At this corpus size (tens to low
 * thousands of rows) FULLTEXT is the right tool and extra infrastructure would
 * be unjustified; see the report for the thresholds at which that stops holding.
 *
 * TWO ACCESS PATHS, chosen by query length:
 *
 *  1. FULLTEXT, the normal path — MATCH(title, body) AGAINST (... IN BOOLEAN
 *     MODE) with a trailing `*` on the final token, so "gam" finds both
 *     "Game Spot Casino" and "Kings Game Casino".
 *
 *  2. A left-anchored LIKE 'x%' on the indexed title, for queries shorter than
 *     innodb_ft_min_token_size — which InnoDB refuses to index at all, so path 1
 *     would return nothing for them. `LIKE '%x%'` is never used anywhere: a
 *     leading wildcard cannot use an index and would turn every keystroke into a
 *     full table scan.
 */
class SearchService
{
    public function __construct(private readonly SearchIndexer $indexer) {}

    /**
     * Grouped suggestions.
     *
     * @return array{query: string, sections: list<array<string, mixed>>, total: int, has_more: bool}
     */
    public function suggest(Site $site, string $query, ?string $section = null, int $page = 1): array
    {
        $term = $this->sanitize($query);
        $per = (int) config('search.per_page', 5);
        $configured = (array) config('search.sections', []);

        if ($term === '') {
            return ['query' => $query, 'sections' => [], 'total' => 0, 'has_more' => false];
        }

        // ONE grouped aggregate for every pill's count — not one query per
        // section. The pills need all five totals on every keystroke, so N
        // queries here would multiply the endpoint's cost by five.
        $counts = $this->counts($site, $term);
        $total = array_sum($counts);

        // A single section: that section only, paginated.
        if ($section !== null) {
            $items = $this->matches($site, $term, $section, $per, ($page - 1) * $per);
            $sectionTotal = $counts[$section] ?? 0;

            return [
                'query'    => $query,
                'sections' => [$this->group($section, $configured, $sectionTotal, $items)],
                'total'    => $total,
                'has_more' => $page * $per < $sectionTotal,
            ];
        }

        // "All": up to $per per section, in configured display order, skipping
        // sections with nothing to show.
        $groups = [];

        foreach (array_keys($configured) as $key) {
            if (($counts[$key] ?? 0) === 0) {
                continue;
            }

            $groups[] = $this->group($key, $configured, $counts[$key], $this->matches($site, $term, $key, $per, 0));
        }

        return [
            'query'    => $query,
            'sections' => $groups,
            'total'    => $total,
            // True when any section holds more than the five shown.
            'has_more' => (bool) array_filter($counts, static fn (int $n): bool => $n > $per),
        ];
    }

    /**
     * Strip everything InnoDB's boolean parser treats as an operator.
     *
     * Without this a visitor typing `"` or `(` produces a syntax error from the
     * parser and a 500 from the endpoint, and `*` alone would match the entire
     * index. Runs BEFORE the query is built, so no operator a user types can
     * ever reach the parser — the only `*` in the final expression is the one
     * this class appends itself.
     */
    public function sanitize(string $query): string
    {
        $stripped = str_replace(
            str_split((string) config('search.boolean_operators', '+-><()~*"@')),
            ' ',
            $query,
        );

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? '');
    }

    /**
     * The boolean-mode expression.
     *
     * Earlier tokens are REQUIRED (`+word`) and the last gets a prefix wildcard
     * (`+word*`), which is what makes the results narrow as the user keeps
     * typing rather than widen: "game spo" must mean "game AND spo…", not
     * "game OR spo…".
     */
    public function booleanExpression(string $term): string
    {
        $tokens = array_values(array_filter(explode(' ', $term), static fn (string $t): bool => $t !== ''));

        if ($tokens === []) {
            return '';
        }

        $last = array_pop($tokens);
        $required = array_map(static fn (string $t): string => '+' . $t, $tokens);
        $required[] = '+' . $last . '*';

        return implode(' ', $required);
    }

    /**
     * Whether this query must be served by the prefix path instead of FULLTEXT.
     *
     * Two reasons, and both are real:
     *
     *  - a token shorter than innodb_ft_min_token_size is not in the index at
     *    all, so MATCH() would return nothing for it;
     *  - the connection has no FULLTEXT support. The test harness runs on
     *    SQLite, where MATCH(...) AGAINST is a syntax error. Rather than let the
     *    suite exercise a query production never runs, the whole service
     *    degrades to the prefix path there — which is also what the driver
     *    boundary in this class exists for.
     */
    public function usesFallback(string $term): bool
    {
        if (! $this->supportsFullText()) {
            return true;
        }

        $shortest = min(array_map('mb_strlen', explode(' ', $term)) ?: [0]);

        return $shortest < (int) config('search.min_token_size', 3);
    }

    /** Does this connection have MySQL FULLTEXT? */
    private function supportsFullText(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }

    /**
     * Per-section totals in ONE aggregate.
     *
     * @return array<string, int>
     */
    private function counts(Site $site, string $term): array
    {
        $rows = $this->base($site, $term)
            ->select('section', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('section')
            ->get();

        return $rows->mapWithKeys(static fn ($r): array => [$r->section => (int) $r->aggregate])->all();
    }

    /**
     * One section's rows, ranked.
     *
     * @return list<array<string, mixed>>
     */
    private function matches(Site $site, string $term, string $section, int $limit, int $offset): array
    {
        $query = $this->base($site, $term)
            ->where('section', $section)
            ->select(['title', 'subtitle', 'url', 'image_url', 'section']);

        $like = $this->escapeLike($term) . '%';

        if ($this->usesFallback($term)) {
            // No relevance score exists on this path, so rank by section weight
            // then alphabetically — stable, and cheap over an index range scan.
            $query->orderByDesc('weight')->orderBy('title');
        } else {
            // THREE TIERS, highest first:
            //
            //   1. the title STARTS with what was typed  -> flat +1000
            //   2. the title matches anywhere            -> title relevance x4
            //   3. the row matches at all (title or body) -> combined relevance
            //
            // all multiplied by the section weight, which is what keeps a casino
            // above a review that merely mentions it.
            //
            // Tier 2 needs its own MATCH against the title-only FULLTEXT index:
            // the combined index scores as one unit, so without it a casino
            // named "Game Spot" ranked level with one whose description happens
            // to say "games". The alternative — title LIKE '%gam%' — is not used
            // anywhere in this codebase, deliberately.
            $expression = $this->booleanExpression($term);

            $query
                ->selectRaw(
                    '(MATCH(title, body) AGAINST (? IN BOOLEAN MODE)) * weight
                     + (MATCH(title) AGAINST (? IN BOOLEAN MODE)) * weight * 4
                     + (CASE WHEN title LIKE ? THEN 1000 ELSE 0 END) AS score',
                    [$expression, $expression, $like],
                )
                ->orderByDesc('score')
                ->orderBy('title');
        }

        return array_map(static fn ($row): array => (array) $row, $query->limit($limit)->offset($offset)->get()->all());
    }

    /**
     * The shared, pre-scoped predicate.
     *
     * Site scoping and is_active are applied here and only here, so no caller
     * can forget them — and because the indexer already resolved visibility,
     * these two columns are the entire access-control check.
     *
     * @return Builder
     */
    private function base(Site $site, string $term): BuilderContract
    {
        $query = DB::table('search_index')
            ->where('site_id', $site->id)
            ->where('is_active', true);

        if ($this->usesFallback($term)) {
            // LEFT-ANCHORED on purpose — range-scans search_index_title_prefix_idx.
            return $query->where('title', 'like', $this->escapeLike($term) . '%');
        }

        return $query->whereRaw(
            'MATCH(title, body) AGAINST (? IN BOOLEAN MODE)',
            [$this->booleanExpression($term)],
        );
    }

    /** Neutralise LIKE metacharacters so `%` typed by a user is a literal. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  array<string, mixed>  $configured
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function group(string $key, array $configured, int $total, array $items): array
    {
        $label = (string) ($configured[$key]['label'] ?? Str::headline($key));

        return [
            'key'   => $key,
            'label' => $label,
            'total' => $total,
            'items' => array_map(static fn (array $item): array => [
                ...$item,
                'section_label' => $label,
            ], $items),
        ];
    }
}
