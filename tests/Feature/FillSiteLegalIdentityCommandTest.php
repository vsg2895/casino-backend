<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CmsPage;
use App\Models\Site;
use App\Support\LegalPageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `sites:fill-legal-identity` — the three facts only a human can supply.
 *
 * Registering a domain seeds eleven legal pages with the entity, the registered
 * address and the governing-law jurisdiction left as bracketed placeholders.
 * They occur thirteen times across three pages, which is why this is a command
 * and not six edits in the admin.
 *
 * The tests that matter most are the ones about what it REFUSES to do: it must
 * never overwrite copy a person has already written, must never touch a site it
 * was not asked about, and must write nothing at all under --dry-run. An
 * over-eager find-and-replace across legal pages is a much worse outcome than
 * one that does too little.
 */
class FillSiteLegalIdentityCommandTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string ENTITY = 'BetPulseHQ Media Ltd';
    private const string ADDRESS = '14 Maple Close, Bristol, BS7 8QR, United Kingdom';
    private const string JURISDICTION = 'England and Wales';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['slug' => 'betpulsehq']);
    }

    /** A page carrying the placeholders the seeder would have left. */
    private function page(string $slug, string $content): CmsPage
    {
        return CmsPage::create([
            'site_id' => $this->site->id,
            'slug'    => $slug,
            'title'   => ucfirst($slug),
            'content' => $content,
            'status'  => 'published',
        ]);
    }

    private function seededContact(): CmsPage
    {
        return $this->page('contact', sprintf(
            '<p>Operated by %s, %s.</p>',
            LegalPageContent::PLACEHOLDER_ENTITY,
            LegalPageContent::PLACEHOLDER_ADDRESS,
        ));
    }

    /** Terms carries the entity FOUR times — the case a manual edit misses. */
    private function seededTerms(): CmsPage
    {
        return $this->page('terms', sprintf(
            '<p>%1$s one.</p><p>%1$s two.</p><p>%1$s three.</p><p>%1$s four.</p><p>Governed by %2$s.</p>',
            LegalPageContent::PLACEHOLDER_ENTITY,
            LegalPageContent::PLACEHOLDER_JURISDICTION,
        ));
    }

    private function fill(array $options = []): int
    {
        return $this->artisan('sites:fill-legal-identity', array_merge([
            'site'           => 'betpulsehq',
            '--entity'       => self::ENTITY,
            '--address'      => self::ADDRESS,
            '--jurisdiction' => self::JURISDICTION,
        ], $options))->run();
    }

    // ── It fills what the seeder left ────────────────────────────────────────

    public function test_it_fills_every_occurrence_including_the_repeated_ones(): void
    {
        $contact = $this->seededContact();
        $terms = $this->seededTerms();

        $this->assertSame(0, $this->fill());

        $this->assertStringNotContainsString(LegalPageContent::PLACEHOLDER_ENTITY, $terms->fresh()->content);
        $this->assertSame(
            4,
            substr_count((string) $terms->fresh()->content, self::ENTITY),
            'all four occurrences on Terms must be replaced, not just the first',
        );
        $this->assertStringContainsString(self::ADDRESS, (string) $contact->fresh()->content);
        $this->assertStringContainsString(self::JURISDICTION, (string) $terms->fresh()->content);
    }

    public function test_each_value_is_independent(): void
    {
        // Filling only the address must leave the entity placeholder alone, so
        // an operator can supply facts as their lawyer confirms them.
        $contact = $this->seededContact();

        $this->assertSame(0, $this->artisan('sites:fill-legal-identity', [
            'site'      => 'betpulsehq',
            '--address' => self::ADDRESS,
        ])->run());

        $content = (string) $contact->fresh()->content;

        $this->assertStringContainsString(self::ADDRESS, $content);
        $this->assertStringContainsString(LegalPageContent::PLACEHOLDER_ENTITY, $content);
    }

    public function test_a_numeric_id_resolves_the_same_site(): void
    {
        $contact = $this->seededContact();

        $this->assertSame(0, $this->artisan('sites:fill-legal-identity', [
            'site'     => (string) $this->site->id,
            '--entity' => self::ENTITY,
        ])->run());

        $this->assertStringContainsString(self::ENTITY, (string) $contact->fresh()->content);
    }

    // ── What it refuses to do ────────────────────────────────────────────────

    public function test_a_dry_run_writes_nothing(): void
    {
        $terms = $this->seededTerms();
        $before = (string) $terms->content;

        $this->assertSame(0, $this->fill(['--dry-run' => true]));

        $this->assertSame($before, (string) $terms->fresh()->content);
    }

    public function test_it_never_overwrites_copy_a_person_already_wrote(): void
    {
        /*
         * THE one that matters. Matching on the bracketed token rather than on
         * a sentence pattern is what makes this safe: a page somebody has
         * already completed contains no placeholder, so there is nothing to
         * replace and the command passes over it.
         */
        $edited = $this->page('contact', '<p>Operated by Someone Else Ltd, 1 Real Street, London.</p>');

        $this->assertSame(0, $this->fill());

        $this->assertSame(
            '<p>Operated by Someone Else Ltd, 1 Real Street, London.</p>',
            (string) $edited->fresh()->content,
        );
    }

    public function test_it_is_idempotent(): void
    {
        $terms = $this->seededTerms();

        $this->assertSame(0, $this->fill());
        $after = (string) $terms->fresh()->content;

        // A second run has no placeholders left to find and must be a no-op
        // rather than, say, replacing the value with itself and bumping rows.
        $this->assertSame(0, $this->fill());
        $this->assertSame($after, (string) $terms->fresh()->content);
    }

    public function test_it_leaves_other_sites_alone(): void
    {
        $other = Site::factory()->create(['slug' => 'sevenstarslots']);
        $otherPage = CmsPage::create([
            'site_id' => $other->id,
            'slug'    => 'contact',
            'title'   => 'Contact',
            'content' => '<p>Operated by ' . LegalPageContent::PLACEHOLDER_ENTITY . '.</p>',
            'status'  => 'published',
        ]);

        $this->seededContact();
        $this->assertSame(0, $this->fill());

        $this->assertStringContainsString(
            LegalPageContent::PLACEHOLDER_ENTITY,
            (string) $otherPage->fresh()->content,
            'a per-site command must not reach across sites',
        );
    }

    public function test_it_refuses_when_no_value_is_given(): void
    {
        // Doing nothing quietly would be indistinguishable from running and
        // finding nothing to do.
        $this->seededContact();

        $this->assertSame(1, $this->artisan('sites:fill-legal-identity', ['site' => 'betpulsehq'])->run());
    }

    public function test_it_fails_on_an_unknown_site(): void
    {
        $this->assertSame(1, $this->artisan('sites:fill-legal-identity', [
            'site'     => 'no-such-site',
            '--entity' => self::ENTITY,
        ])->run());
    }
}
