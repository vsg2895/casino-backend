<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * GET /api/v1/admin/newsletters/export
 *
 * The export is what leaves the system and lands in someone's spreadsheet, so
 * the columns are a contract: assert the header row and the cells, not just that
 * a file came back.
 */
class NewsletterExportTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function csv(?int $siteId = null): string
    {
        $this->actingAsAdmin();

        $url = '/api/v1/admin/newsletters/export' . ($siteId ? '?site_id=' . $siteId : '');

        return $this->get($url)->assertOk()->streamedContent();
    }

    /** @return list<string> */
    private function lines(string $csv): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $csv)), fn ($l) => $l !== ''));
    }

    public function test_the_header_row_carries_verified_at(): void
    {
        $header = $this->lines($this->csv())[0] ?? '';

        $this->assertStringContainsString('Email address', $header);
        $this->assertStringContainsString('Created at', $header);
        $this->assertStringContainsString('Verified at', $header);
    }

    public function test_a_verified_subscriber_exports_its_verification_time(): void
    {
        $site = Site::factory()->create();

        $subscriber = Newsletter::create([
            'site_id'  => $site->id,
            'email'    => 'confirmed@example.com',
            'verified' => true,
        ]);
        $subscriber->forceFill([
            'created_at'  => Carbon::parse('2026-09-06 10:43:45'),
            'verified_at' => Carbon::parse('2026-09-06 13:56:45'),
        ])->saveQuietly();

        $csv = $this->csv();

        $this->assertStringContainsString('confirmed@example.com', $csv);
        $this->assertStringContainsString('06/09/2026, 10:43 AM', $csv);
        $this->assertStringContainsString('06/09/2026, 1:56 PM', $csv);
    }

    /**
     * An unverified subscriber must produce an EMPTY cell, not a placeholder —
     * the column is a date, and a spreadsheet can filter a blank but not a dash
     * pretending to be one.
     */
    public function test_an_unverified_subscriber_exports_an_empty_verified_cell(): void
    {
        $site = Site::factory()->create();

        Newsletter::create([
            'site_id'  => $site->id,
            'email'    => 'pending@example.com',
            'verified' => false,
        ]);

        $row = collect($this->lines($this->csv()))->first(fn ($l) => str_contains($l, 'pending@example.com'));

        $this->assertNotNull($row);

        // Three columns, and the last one empty.
        $cells = str_getcsv($row);
        $this->assertCount(3, $cells);
        $this->assertSame('', $cells[2]);
    }

    public function test_the_site_filter_still_applies(): void
    {
        $a = Site::factory()->create();
        $b = Site::factory()->create();

        Newsletter::create(['site_id' => $a->id, 'email' => 'ours@example.com', 'verified' => true]);
        Newsletter::create(['site_id' => $b->id, 'email' => 'theirs@example.com', 'verified' => true]);

        $csv = $this->csv($a->id);

        $this->assertStringContainsString('ours@example.com', $csv);
        $this->assertStringNotContainsString('theirs@example.com', $csv);
    }

    /** Tokens are secrets and must never reach an exported file. */
    public function test_the_export_leaks_no_unsubscribe_tokens(): void
    {
        $site = Site::factory()->create();
        $subscriber = Newsletter::create([
            'site_id'  => $site->id,
            'email'    => 'tokens@example.com',
            'verified' => true,
        ]);

        $csv = $this->csv();

        foreach ([
            $subscriber->unsubscribe_token,
            $subscriber->promotion_unsubscribe_token,
            $subscriber->verify_unsubscribe_token,
            $subscriber->verification_promotion_unsubscribe_token,
        ] as $token) {
            $this->assertNotEmpty($token, 'The fixture should have generated a token.');
            $this->assertStringNotContainsString($token, $csv);
        }
    }
}
