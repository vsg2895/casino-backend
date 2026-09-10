<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Search\SearchIndexer;
use App\Services\Search\SearchService;
use Tests\TestCase;

/**
 * The pure parts of the query builder: sanitisation, boolean-mode expression
 * construction and the short-query decision.
 *
 * These need no database, which is the point — they are the pieces where a
 * mistake is a security problem (an operator reaching the parser) or a silent
 * wrong-results problem (a query that can never match), and both must be
 * provable without a MySQL connection.
 */
class SearchServiceTest extends TestCase
{
    private function service(): SearchService
    {
        return new SearchService(new SearchIndexer());
    }

    public function test_it_strips_every_boolean_mode_operator(): void
    {
        $service = $this->service();

        // Each of these means something to InnoDB's parser; unescaped, they
        // either error or match far more than the visitor asked for.
        $this->assertSame('bad query', $service->sanitize('+bad"(query)*'));
        $this->assertSame('game', $service->sanitize('  game  '));
        $this->assertSame('a b', $service->sanitize('a   ~b'));
        $this->assertSame('', $service->sanitize('*'));
        $this->assertSame('', $service->sanitize('+-><()~*"@'));
        $this->assertSame('mail', $service->sanitize('@mail'));
    }

    public function test_it_collapses_whitespace_rather_than_producing_empty_tokens(): void
    {
        // An operator between words becomes a space, which must not leave a
        // zero-length token that would produce "+ *" in the expression.
        $this->assertSame('free spin', $this->service()->sanitize('free  ~  spin'));
    }

    public function test_only_the_last_token_gets_a_prefix_wildcard(): void
    {
        $service = $this->service();

        // Earlier tokens are REQUIRED, so typing more narrows the result set
        // instead of widening it.
        $this->assertSame('+gam*', $service->booleanExpression('gam'));
        $this->assertSame('+free +spin*', $service->booleanExpression('free spin'));
        $this->assertSame('+a +b +c*', $service->booleanExpression('a b c'));
    }

    public function test_an_empty_term_produces_no_expression(): void
    {
        $this->assertSame('', $this->service()->booleanExpression(''));
    }

    public function test_short_queries_take_the_prefix_path(): void
    {
        config(['search.min_token_size' => 3]);
        $service = $this->service();

        // Below the token size FULLTEXT would return nothing at all, so these
        // MUST be routed to the indexed prefix scan.
        $this->assertTrue($service->usesFallback('b'));
        $this->assertTrue($service->usesFallback('ki'));

        // A multi-word query is only as strong as its shortest token.
        $this->assertTrue($service->usesFallback('game o'));
    }
}
