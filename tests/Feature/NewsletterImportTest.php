<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ImportNewslettersJob;
use App\Jobs\ProcessNewsletterSubscription;
use App\Jobs\SendNewsletterWelcomeEmail;
use App\Models\Newsletter;
use App\Models\NewsletterImport;
use App\Models\Site;
use App\Services\NewsletterImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class NewsletterImportTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** @param list<array<int, string>> $rows */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        $this->tempFiles[] = $path;

        $writer = new XlsxWriter();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'subscribers.xlsx', null, null, true);
    }

    /** @param list<array<int, string>> $rows */
    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        $this->tempFiles[] = $path;

        $writer = new CsvWriter();
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'subscribers.csv', null, null, true);
    }

    /** @param array<string, mixed> $extra */
    private function import(Site $site, UploadedFile $file, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            '/api/v1/admin/newsletters/import',
            ['site_id' => $site->id, 'file' => $file, ...$extra],
            ['Accept' => 'application/json'],
        );
    }

    // ── Happy paths ───────────────────────────────────────────────────────

    public function test_imports_emails_from_xlsx_with_email_column(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $file = $this->xlsx([
            ['Name', 'Email'],
            ['Alice', 'alice@example.com'],
            ['Bob', 'bob@example.com'],
        ]);

        $this->import($site, $file)
            ->assertAccepted()
            ->assertJson(['imported' => 2, 'skipped' => 0, 'total' => 2]);

        $this->assertDatabaseHas('newsletters', ['site_id' => $site->id, 'email' => 'alice@example.com']);
        $this->assertDatabaseHas('newsletters', ['site_id' => $site->id, 'email' => 'bob@example.com']);
    }

    public function test_imports_from_csv(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->import($site, $this->csv([['Email'], ['csv@example.com']]))
            ->assertAccepted()
            ->assertJson(['imported' => 1]);

        $this->assertDatabaseHas('newsletters', ['site_id' => $site->id, 'email' => 'csv@example.com']);
    }

    public function test_only_the_email_column_is_used(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // A second column also holds emails but must be ignored.
        $file = $this->xlsx([
            ['Email', 'Referrer'],
            ['real@example.com', 'ignored@example.com'],
        ]);

        $this->import($site, $file)->assertAccepted()->assertJson(['imported' => 1]);
        $this->assertDatabaseHas('newsletters', ['email' => 'real@example.com']);
        $this->assertDatabaseMissing('newsletters', ['email' => 'ignored@example.com']);
    }

    public function test_dedupes_normalises_and_skips_invalid(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $file = $this->xlsx([
            ['Email'],
            ['  Dup@Example.com '],  // whitespace + mixed case
            ['dup@example.com'],     // duplicate after normalising
            ['not-an-email'],        // invalid → skipped
            [''],                    // blank → skipped
            ['fresh@example.com'],
        ]);

        $this->import($site, $file)->assertAccepted()->assertJson(['imported' => 2, 'total' => 2]);
        $this->assertDatabaseHas('newsletters', ['email' => 'dup@example.com']);
        $this->assertSame(2, Newsletter::where('site_id', $site->id)->count());
    }

    public function test_headerless_file_still_imports(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // No "Email" header — a plain one-column list.
        $this->import($site, $this->xlsx([['a@example.com'], ['b@example.com']]))
            ->assertAccepted()
            ->assertJson(['imported' => 2]);
    }

    public function test_existing_active_subscribers_are_skipped(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'existing@example.com']);

        $this->import($site, $this->xlsx([['Email'], ['existing@example.com'], ['new@example.com']]))
            ->assertAccepted()
            ->assertJson(['imported' => 1, 'skipped' => 1]);
    }

    public function test_previously_unsubscribed_rows_are_restored(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'back@example.com']);
        $n->delete(); // soft-deleted

        $this->import($site, $this->xlsx([['Email'], ['back@example.com']]))
            ->assertAccepted()
            ->assertJson(['imported' => 1]);

        $this->assertNotSoftDeleted('newsletters', ['id' => $n->id]);
    }

    public function test_import_is_queued_on_the_high_priority_queue(): void
    {
        Queue::fake();
        Storage::fake('local'); // the job never runs here — don't leave the upload behind
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->import($site, $this->xlsx([['Email'], ['queued@example.com']]))
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('finished', false);

        Queue::assertPushed(
            ImportNewslettersJob::class,
            fn (ImportNewslettersJob $job): bool => $job->queue === ImportNewslettersJob::ON_QUEUE,
        );

        // The row exists and the upload is staged for the worker to read.
        $this->assertDatabaseHas('newsletter_imports', [
            'site_id'  => $site->id,
            'filename' => 'subscribers.xlsx',
            'status'   => 'queued',
        ]);
    }

    public function test_import_does_not_send_welcome_emails(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->import($site, $this->xlsx([['Email'], ['quiet@example.com']]))->assertStatus(202);

        // The import job itself is expected; the subscription/welcome pipeline
        // must stay untouched — a list import is not a public subscription.
        Queue::assertPushed(ImportNewslettersJob::class);
        Queue::assertNotPushed(ProcessNewsletterSubscription::class);
        Queue::assertNotPushed(SendNewsletterWelcomeEmail::class);
    }

    // ── Verified flag ─────────────────────────────────────────────────────

    public function test_imports_are_unverified_by_default(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->import($site, $this->xlsx([['Email'], ['plain@example.com']]))->assertAccepted();

        $this->assertDatabaseHas('newsletters', [
            'site_id'  => $site->id,
            'email'    => 'plain@example.com',
            'verified' => false,
        ]);
    }

    public function test_can_import_as_verified_when_requested(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->import($site, $this->xlsx([['Email'], ['trusted@example.com']]), ['verified' => '1'])->assertAccepted();

        $this->assertDatabaseHas('newsletters', [
            'site_id'  => $site->id,
            'email'    => 'trusted@example.com',
            'verified' => true,
        ]);
    }

    public function test_reimport_never_downgrades_an_already_verified_subscriber(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'back@example.com', 'verified' => true]);
        $n->delete();

        // Re-import as unverified (default) → the restored row STAYS verified.
        $this->import($site, $this->xlsx([['Email'], ['back@example.com']]))->assertAccepted();

        $this->assertNotSoftDeleted('newsletters', ['id' => $n->id]);
        $this->assertDatabaseHas('newsletters', ['id' => $n->id, 'verified' => true]);
    }

    public function test_reimport_can_upgrade_an_unverified_removed_subscriber(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $n = Newsletter::create(['site_id' => $site->id, 'email' => 'back@example.com', 'verified' => false]);
        $n->delete();

        // Re-import as verified → the restored row is promoted.
        $this->import($site, $this->xlsx([['Email'], ['back@example.com']]), ['verified' => '1'])->assertAccepted();

        $this->assertDatabaseHas('newsletters', ['id' => $n->id, 'verified' => true]);
    }

    // ── Validation ────────────────────────────────────────────────────────

    public function test_rejects_non_spreadsheet_files(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->post(
            '/api/v1/admin/newsletters/import',
            ['site_id' => $site->id, 'file' => UploadedFile::fake()->create('list.pdf', 10)],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrorFor('file');
    }

    public function test_requires_a_valid_site(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->post(
            '/api/v1/admin/newsletters/import',
            ['file' => $this->xlsx([['Email'], ['x@example.com']])],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrorFor('site_id');
    }

    public function test_import_requires_auth(): void
    {
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/newsletters/import', ['site_id' => $site->id])
            ->assertUnauthorized();
    }

    public function test_file_with_no_emails_completes_with_a_hint(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        // The upload is accepted (it is well-formed); the JOB reports that it
        // found nothing usable, since parsing no longer happens in the request.
        $this->import($site, $this->xlsx([['Email'], ['nope'], ['also-bad']]))
            ->assertStatus(202)
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('total', 0)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('message', 'No valid email addresses found. Make sure the file has an "Email" column.');
    }

    public function test_a_failed_import_is_recorded_with_its_reason(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $import = NewsletterImport::create([
            'site_id'  => $site->id,
            'filename' => 'gone.csv',
            'path'     => 'newsletter-imports/does-not-exist.csv',
            'status'   => NewsletterImport::STATUS_QUEUED,
        ]);

        (new ImportNewslettersJob($import->id))->handle(app(NewsletterImportService::class));

        $this->assertSame(NewsletterImport::STATUS_FAILED, $import->fresh()->status);
        $this->assertNotNull($import->fresh()->error);
    }
}
