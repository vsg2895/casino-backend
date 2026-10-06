<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NewsletterBasedOnPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `?search=` on the phone-newsletter list.
 *
 * The search already existed and had no test. It is the mirror of the email
 * list's, with one deliberate difference in shape: a plain term matches
 * ANYWHERE, because an admin looking for a number knows its tail rather than its
 * country code, and a term beginning with `+` is a prefix, served by the unique
 * index. Both forms reduce the term to digits first, since the column holds
 * E.164 and nobody types it that way.
 *
 * Pinned here because all three surfaces — listing, count and export — read the
 * same builder: a change to the scope that broke one would break the badge's
 * agreement with the rows, which is the bug this shared builder exists to
 * prevent.
 */
class PhoneNewsletterSearchTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsAdmin();
    }

    private function number(string $phone, bool $optedOut = false): NewsletterBasedOnPhone
    {
        return NewsletterBasedOnPhone::create([
            'phone'     => $phone,
            'opted_out' => $optedOut,
        ]);
    }

    /** @return list<string> Phone numbers the listing returned. */
    private function search(?string $term, array $extra = []): array
    {
        $query = array_filter(['search' => $term, ...$extra], fn ($v) => $v !== null);

        return array_column(
            $this->getJson('/api/v1/admin/newsletter-phones?' . http_build_query($query))->assertOk()->json('data'),
            'phone',
        );
    }

    private function total(?string $term): int
    {
        $query = array_filter(['search' => $term], fn ($v) => $v !== null);

        return (int) $this->getJson('/api/v1/admin/newsletter-phones/count?' . http_build_query($query))
            ->assertOk()->json('total');
    }

    public function test_a_partial_number_matches_anywhere(): void
    {
        $this->number('+15550100199');
        $this->number('+447700900123');

        // The tail, which is what someone reading a number off a note has.
        $this->assertSame(['+15550100199'], $this->search('0199'));
    }

    public function test_a_plus_makes_it_a_prefix(): void
    {
        $this->number('+15550100199');
        $this->number('+447700900155');

        // '155' appears in BOTH numbers, but only one STARTS with it.
        $this->assertSame(['+15550100199'], $this->search('+155'));
    }

    public function test_punctuation_is_ignored(): void
    {
        $this->number('+15550100199');

        // Typed the way a human writes it down, not the way it is stored.
        $this->assertSame(['+15550100199'], $this->search('(555) 010-0199'));
    }

    public function test_a_term_with_no_digits_matches_everyone(): void
    {
        $this->number('+15550100199');
        $this->number('+447700900123');

        // Nothing to search on, so it is not a search — better than returning
        // an empty list and reading as "you have no subscribers".
        $this->assertCount(2, $this->search('abc'));
        $this->assertCount(2, $this->search(''));
    }

    public function test_the_count_matches_the_rows(): void
    {
        $this->number('+15550100199');
        $this->number('+15550100255');
        $this->number('+447700900123');

        $this->assertSame(2, $this->total('+1555'));
        $this->assertSame(3, $this->total(null));
    }

    public function test_the_search_combines_with_the_opted_out_filter(): void
    {
        $this->number('+15550100199');
        $this->number('+15550100255', optedOut: true);

        $this->assertSame(['+15550100255'], $this->search('+1555', ['opted_out' => '1']));
        $this->assertSame(['+15550100199'], $this->search('+1555', ['opted_out' => '0']));
        $this->assertCount(2, $this->search('+1555'));
    }

    public function test_the_export_carries_the_same_search(): void
    {
        $this->number('+15550100199');
        $this->number('+447700900123');

        $csv = $this->get('/api/v1/admin/newsletter-phones/export?search=0199')->assertOk()->streamedContent();

        $this->assertStringContainsString('+15550100199', $csv);
        $this->assertStringNotContainsString('+447700900123', $csv);
    }
}
