<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MailgunReceiverImport;
use App\Services\MailgunReceiverImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Parses an uploaded receiver list into `mailgun_receivers`.
 *
 * Runs on HIGH, like {@see ImportNewslettersJob}: an admin is watching the
 * progress bar, so it must not queue behind a 100k-recipient send on `low`.
 *
 * Only the import id travels in the payload — the file was staged on disk by
 * the upload endpoint and is read, then deleted, here.
 *
 * NOT retried ($tries = 1), for the same reason the newsletter import is not:
 * the writes are idempotent so a retry would not corrupt data, but it would
 * double-count a partially finished run and report totals the admin cannot
 * reconcile. Failures are recorded on the import row with their reason.
 */
class ImportMailgunReceiversJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'high';

    public int $tries = 1;

    /** Scales with file size; stays below the queue's retry_after. */
    public int $timeout = 900;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(MailgunReceiverImportService $importer): void
    {
        $import = MailgunReceiverImport::find($this->importId);

        if ($import === null || $import->status !== MailgunReceiverImport::STATUS_QUEUED) {
            // Missing, or already picked up — never re-run a finished import.
            return;
        }

        $relative = (string) $import->path;
        $absolute = Storage::disk('local')->path($relative);

        if ($relative === '' || ! is_file($absolute)) {
            $import->forceFill([
                'status'      => MailgunReceiverImport::STATUS_FAILED,
                'error'       => 'The uploaded file is no longer available.',
                'finished_at' => now(),
            ])->save();

            return;
        }

        try {
            $importer->import(
                $import,
                $absolute,
                pathinfo((string) $import->filename, PATHINFO_EXTENSION),
            );
        } catch (Throwable $e) {
            $import->forceFill([
                'status'      => MailgunReceiverImport::STATUS_FAILED,
                'error'       => mb_strimwidth($e->getMessage(), 0, 1000, '…'),
                'finished_at' => now(),
            ])->save();

            Log::warning('Mailgun receiver import failed', [
                'import_id' => $import->id,
                'error'     => $e->getMessage(),
            ]);
        } finally {
            // The staged upload is transient in every outcome. Leaving it would
            // accumulate spreadsheets of personal data on disk indefinitely.
            Storage::disk('local')->delete($relative);
        }
    }
}
