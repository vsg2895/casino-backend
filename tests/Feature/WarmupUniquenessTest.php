<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\WarmupEmail;
use App\Services\WarmupImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The warmup list holds each address exactly once.
 *
 * Three routes in, three behaviours, and they differ on purpose:
 *
 *  - ADD, one address — a duplicate is an ERROR the operator should see. They
 *    typed it, so silence would look like it worked.
 *  - EDIT — same, ignoring the row being edited so saving it unchanged is fine.
 *  - IMPORT, a file — a duplicate is SKIPPED and counted. A spreadsheet of a
 *    thousand addresses that overlaps an existing list is normal, not a mistake,
 *    and rejecting the whole file over it would be useless.
 *
 * Underneath all three is the unique index, which is what makes a re-import
 * idempotent regardless of what the application layer does.
 */
class WarmupUniquenessTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @return array{0: string, 1: string} path + extension */
    private function csv(string ...$rows): array
    {
        $path = tempnam(sys_get_temp_dir(), 'warmup') . '.csv';
        file_put_contents($path, "Email\n" . implode("\n", $rows) . "\n");

        return [$path, 'csv'];
    }

    // ── The constraint itself ────────────────────────────────────────────────

    public function test_the_email_column_is_unique(): void
    {
        // The backstop every other behaviour rests on.
        $this->assertTrue(Schema::hasColumn('warmup_emails', 'email'));

        WarmupEmail::create(['email' => 'seed@example.com']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        WarmupEmail::create(['email' => 'seed@example.com']);
    }

    // ── Add one address ──────────────────────────────────────────────────────

    public function test_adding_a_duplicate_address_is_rejected_with_a_message(): void
    {
        $this->actingAsAdmin();
        WarmupEmail::create(['email' => 'seed@example.com']);

        $this->postJson('/api/v1/admin/warmup-emails', ['email' => 'seed@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'That address is already on the warmup list.');

        $this->assertSame(1, WarmupEmail::count(), 'nothing may be added');
    }

    public function test_a_duplicate_differing_only_in_case_or_spacing_is_rejected(): void
    {
        // The realistic paste: "  Seed@Example.COM ". Normalised before the
        // unique rule runs, so it is caught as the duplicate it is.
        $this->actingAsAdmin();
        WarmupEmail::create(['email' => 'seed@example.com']);

        $this->postJson('/api/v1/admin/warmup-emails', ['email' => '  Seed@Example.COM '])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, WarmupEmail::count());
    }

    public function test_a_new_address_is_added_and_stored_normalised(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/warmup-emails', ['email' => '  New@Example.COM '])
            ->assertCreated();

        $this->assertSame('new@example.com', WarmupEmail::sole()->email);
    }

    // ── Edit ─────────────────────────────────────────────────────────────────

    public function test_editing_onto_another_existing_address_is_rejected(): void
    {
        $this->actingAsAdmin();
        $a = WarmupEmail::create(['email' => 'a@example.com']);
        WarmupEmail::create(['email' => 'b@example.com']);

        $this->putJson("/api/v1/admin/warmup-emails/{$a->id}", ['email' => 'b@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame('a@example.com', $a->refresh()->email);
    }

    public function test_saving_an_address_unchanged_is_allowed(): void
    {
        // The unique rule must ignore the row being edited, or an unchanged save
        // would collide with itself.
        $this->actingAsAdmin();
        $a = WarmupEmail::create(['email' => 'a@example.com']);

        $this->putJson("/api/v1/admin/warmup-emails/{$a->id}", ['email' => 'a@example.com'])
            ->assertOk();
    }

    // ── Import ───────────────────────────────────────────────────────────────

    public function test_import_skips_addresses_already_on_the_list(): void
    {
        WarmupEmail::create(['email' => 'existing@example.com']);

        [$path, $ext] = $this->csv('existing@example.com', 'fresh@example.com');
        $summary = app(WarmupImportService::class)->import($path, $ext);
        unlink($path);

        $this->assertSame(2, $summary['rows']);
        $this->assertSame(1, $summary['imported'], 'only the new address is added');
        $this->assertSame(1, $summary['duplicates'], 'the existing one is counted, not an error');
        $this->assertSame(2, WarmupEmail::count());
    }

    public function test_import_skips_duplicates_within_the_file_itself(): void
    {
        [$path, $ext] = $this->csv('dup@example.com', 'DUP@example.com', 'other@example.com');
        $summary = app(WarmupImportService::class)->import($path, $ext);
        unlink($path);

        $this->assertSame(2, $summary['imported']);
        $this->assertSame(1, $summary['duplicates']);
        $this->assertSame(2, WarmupEmail::count());
    }

    public function test_re_importing_the_same_file_adds_nothing(): void
    {
        // Idempotence: the property the unique index exists to guarantee.
        [$path, $ext] = $this->csv('a@example.com', 'b@example.com');

        app(WarmupImportService::class)->import($path, $ext);
        $second = app(WarmupImportService::class)->import($path, $ext);
        unlink($path);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(2, $second['duplicates']);
        $this->assertSame(2, WarmupEmail::count());
    }

    public function test_import_does_not_fail_the_whole_file_over_a_duplicate(): void
    {
        // A thousand-row spreadsheet overlapping the list is normal; rejecting it
        // outright would make the importer useless.
        WarmupEmail::create(['email' => 'existing@example.com']);

        [$path, $ext] = $this->csv('existing@example.com', 'one@example.com', 'two@example.com');
        $summary = app(WarmupImportService::class)->import($path, $ext);
        unlink($path);

        $this->assertSame(2, $summary['imported']);
        $this->assertTrue(WarmupEmail::where('email', 'one@example.com')->exists());
        $this->assertTrue(WarmupEmail::where('email', 'two@example.com')->exists());
    }
}
