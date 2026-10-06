<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `?search=` on the admin subscriber list.
 *
 * The list is the only way to find one person among tens of thousands, and
 * paging to them is not finding them. Two behaviours are worth pinning:
 *
 *  - the search is a PREFIX match, except for a term starting with `@`, which
 *    matches anywhere — that is the "everyone at this domain" case, and it is
 *    the one shape no index can serve, so it is opt-in rather than the default;
 *  - the count endpoint applies the same term, because a badge reading 48,000
 *    above three rows is worse than no badge at all.
 */
class NewsletterSearchTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site] = $this->siteWithKey();
        $this->actingAsAdmin();
    }

    private function subscriber(string $email, ?Site $site = null): Newsletter
    {
        return Newsletter::create([
            'site_id' => ($site ?? $this->site)->id,
            'email'   => $email,
        ]);
    }

    /** @return list<string> Emails the listing returned, in its own order. */
    private function search(?string $term, array $extra = []): array
    {
        $query = array_filter([
            'site_id' => $this->site->id,
            'search'  => $term,
            ...$extra,
        ], fn ($v) => $v !== null);

        return array_column(
            $this->getJson('/api/v1/admin/newsletters?' . http_build_query($query))->assertOk()->json('data'),
            'email',
        );
    }

    private function total(?string $term): int
    {
        $query = array_filter(['site_id' => $this->site->id, 'search' => $term], fn ($v) => $v !== null);

        return (int) $this->getJson('/api/v1/admin/newsletters/count?' . http_build_query($query))
            ->assertOk()->json('total');
    }

    public function test_a_prefix_finds_the_address(): void
    {
        $this->subscriber('kate@example.com');
        $this->subscriber('kevin@example.com');

        $this->assertSame(['kate@example.com'], $this->search('kate'));
    }

    public function test_a_whole_address_pasted_in_finds_exactly_that_row(): void
    {
        $this->subscriber('kate@example.com');
        $this->subscriber('kate@other.com');

        $this->assertSame(['kate@example.com'], $this->search('kate@example.com'));
    }

    public function test_a_term_starting_with_an_at_matches_a_domain_anywhere(): void
    {
        $this->subscriber('kate@gmail.com');
        $this->subscriber('mo@gmail.com');
        $this->subscriber('kate@example.com');

        $found = $this->search('@gmail.com');
        sort($found);

        $this->assertSame(['kate@gmail.com', 'mo@gmail.com'], $found);
    }

    public function test_a_term_that_is_not_a_prefix_matches_nothing(): void
    {
        // Deliberate: "example.com" is the tail of the address, not its start.
        // Searching a domain is what the `@` form is for.
        $this->subscriber('kate@example.com');

        $this->assertSame([], $this->search('example.com'));
    }

    public function test_the_search_is_case_insensitive(): void
    {
        $this->subscriber('kate@example.com');

        $this->assertSame(['kate@example.com'], $this->search('KATE'));
    }

    public function test_an_underscore_is_matched_literally(): void
    {
        // Unescaped, `_` is LIKE's single-character wildcard, so this term would
        // also return `aXb@x.com` — a different person's address.
        $this->subscriber('a_b@example.com');
        $this->subscriber('axb@example.com');

        $this->assertSame(['a_b@example.com'], $this->search('a_b'));
    }

    public function test_a_percent_sign_is_matched_literally(): void
    {
        $this->subscriber('kate@example.com');

        // Unescaped this is "match everything"; escaped it matches nobody.
        $this->assertSame([], $this->search('%'));
    }

    public function test_an_empty_term_is_no_search_at_all(): void
    {
        $this->subscriber('kate@example.com');
        $this->subscriber('mo@example.com');

        $this->assertCount(2, $this->search(''));
        $this->assertCount(2, $this->search('   '));
    }

    public function test_the_count_matches_the_rows(): void
    {
        $this->subscriber('kate@example.com');
        $this->subscriber('kevin@example.com');
        $this->subscriber('mo@example.com');

        $this->assertSame(2, $this->total('k'));
        $this->assertSame(3, $this->total(null));
    }

    public function test_the_search_stays_inside_the_chosen_site(): void
    {
        [$other] = $this->siteWithKey();
        $this->subscriber('kate@example.com');
        $this->subscriber('kate@example.com', $other);

        $this->assertCount(1, $this->search('kate'));
        $this->assertSame(1, $this->total('kate'));
    }

    public function test_the_search_combines_with_the_verified_filter(): void
    {
        $this->subscriber('kate@example.com')->update(['verified' => true]);
        $this->subscriber('kevin@example.com');

        $this->assertSame(['kate@example.com'], $this->search('k', ['verified' => '1']));
        $this->assertSame(['kevin@example.com'], $this->search('k', ['verified' => '0']));
    }

    public function test_the_search_works_in_the_trash_view(): void
    {
        $this->subscriber('kate@example.com')->delete();
        $this->subscriber('mo@example.com')->delete();
        $this->subscriber('kevin@example.com');

        $this->assertSame(['kate@example.com'], $this->search('kate', ['trashed' => '1']));
        // The live row is not in the trash view, however well it matches.
        $this->assertSame([], $this->search('kevin', ['trashed' => '1']));
    }
}
