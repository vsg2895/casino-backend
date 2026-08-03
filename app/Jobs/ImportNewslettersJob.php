<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NewsletterImport;
use App\Services\NewsletterImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Parses an uploaded subscriber list and writes it to the newsletters table.
 *
 * Runs on the HIGH-priority queue (worker: --queue=high,low): an admin is
 * waiting on the result, so it must not queue behind a 50k-recipient promotion
 * campaign on `low`.
 *
 * Only the import id travels in the payload. The file itself was staged on the
 * local disk by the upload endpoint and is read — then deleted — here, so the
 * queue never carries megabytes of spreadsheet.
 *
 * NOT retried ($tries = 1). The underlying write is idempotent (existing
 * addresses are skipped, removed ones restored), so a retry would be harmless
 * to the data, but it would double-count a partially-finished run and report
 * numbers the admin cannot reconcile. A failure is recorded on the import row
 * with its reason, and the admin re-uploads.
 */
class ImportNewslettersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Priority queue this job runs on (worker: --queue=high,low). */
    public const string ON_QUEUE = 'high';

    public int $tries = 1;

    /**
     * Generous, because runtime scales with the file. MUST stay below the queue
     * connection's `retry_after`, or a long import gets handed to a second
     * worker while the first is still writing.
     */
    public int $timeout;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue(self::ON_QUEUE);
        $this->timeout = (int) config('newsletters.import_timeout', 900);
    }

    public function handle(NewsletterImportService $importer): void
    {
        $import = NewsletterImport::find($this->importId);

        if ($import === null || $import->isFinished()) {
            return;
        }

        $relativePath = (string) $import->path;
        $disk = Storage::disk('local');

        if ($relativePath === '' || ! $disk->exists($relativePath)) {
            $import->markFailed('The uploaded file is no longer available on the server.');

            return;
        }

        $import->markProcessing();

        try {
            $result = $importer->import(
                $import->site_id,
                $disk->path($relativePath),
                $import->extension(),
                $import->verified,
                // Batch-by-batch progress, so the admin panel shows a live count
                // instead of an opaque spinner on a long file.
                static fn (array $progress) => $import->recordProgress($progress),
            );

            $import->markCompleted($result);

            Log::info('Newsletter import completed', [
                'import_id' => $import->id,
                'site_id'   => $import->site_id,
                ...$result,
            ]);
        } catch (Throwable $e) {
            $import->markFailed($e->getMessage());

            Log::error('Newsletter import failed', [
                'import_id' => $import->id,
                'site_id'   => $import->site_id,
                'error'     => $e->getMessage(),
            ]);
        } finally {
            $this->discardUpload($import);
        }
    }

    /** Remove the staged upload — it has served its purpose either way. */
    private function discardUpload(NewsletterImport $import): void
    {
        $path = (string) $import->path;

        if ($path === '') {
            return;
        }

        try {
            Storage::disk('local')->delete($path);
            $import->forceFill(['path' => null])->save();
        } catch (Throwable $e) {
            // A leftover temp file is not worth failing a finished import over.
            Log::warning('Could not delete a staged newsletter import file', [
                'import_id' => $import->id,
                'path'      => $path,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** Retries are off, so this fires on the first hard failure (e.g. timeout). */
    public function failed(Throwable $e): void
    {
        $import = NewsletterImport::find($this->importId);

        if ($import === null || $import->isFinished()) {
            return;
        }

        $import->markFailed($e->getMessage());
        $this->discardUpload($import);
    }
}
