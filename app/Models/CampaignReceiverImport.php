<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Mail\ReceiverSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Progress and outcome of one receiver spreadsheet import, for either Custom
 * Receivers list: {@see MailgunReceiverImport} or {@see SendgridReceiverImport}.
 *
 * Polled by the admin until `finished_at` is set, exactly as the newsletter
 * import is, so a large file reports real numbers instead of a spinner. The
 * import row names the list it writes to through {@see receiverModel()}, so
 * {@see \App\Services\MailgunReceiverImportService} serves both lists.
 */
abstract class CampaignReceiverImport extends Model
{
    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_FINISHED = 'finished';
    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'filename', 'path', 'status',
        // The source label every row in this file is stamped with.
        'source_label',
        'total', 'imported', 'duplicates', 'suppressed', 'rejected',
        'rejected_rows', 'error', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'finished_at'  => 'datetime',
            // Whose list the uploaded file is — stamped on every row it creates.
            'source_label' => ReceiverSource::class,
            'total'       => 'integer',
            'imported'    => 'integer',
            'duplicates'  => 'integer',
            'suppressed'  => 'integer',
            'rejected'    => 'integer',
        ];
    }

    /**
     * The list this file is imported into.
     *
     * @return class-string<CampaignReceiver>
     */
    abstract public function receiverModel(): string;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
