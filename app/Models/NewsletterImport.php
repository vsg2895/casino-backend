<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Status record for one queued subscriber-list import (see the
 * create_newsletter_imports migration).
 *
 * Created by the admin upload endpoint, then owned by
 * {@see \App\Jobs\ImportNewslettersJob}: the job moves it through
 * queued → processing → completed|failed and keeps the counters current so the
 * admin panel can poll a live progress figure.
 */
class NewsletterImport extends Model
{
    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [
        self::STATUS_QUEUED, self::STATUS_PROCESSING,
        self::STATUS_COMPLETED, self::STATUS_FAILED,
    ];

    protected $fillable = [
        'site_id', 'user_id', 'filename', 'path', 'verified',
        'status', 'total', 'imported', 'skipped', 'error', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'verified'    => 'boolean',
            'total'       => 'integer',
            'imported'    => 'integer',
            'skipped'     => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Extension of the uploaded file, used to pick the spreadsheet reader. */
    public function extension(): string
    {
        return strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
    }

    /** Whether the job has finished, either way — the admin can stop polling. */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    public function markProcessing(): void
    {
        $this->forceFill(['status' => self::STATUS_PROCESSING])->save();
    }

    /** @param array{imported: int, skipped: int, total: int} $progress */
    public function recordProgress(array $progress): void
    {
        $this->forceFill($progress)->save();
    }

    /** @param array{imported: int, skipped: int, total: int} $result */
    public function markCompleted(array $result): void
    {
        $this->forceFill([
            ...$result,
            'status'      => self::STATUS_COMPLETED,
            'error'       => null,
            'finished_at' => Carbon::now(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status'      => self::STATUS_FAILED,
            // Keep only the diagnostic head; transport/parser errors can be huge.
            'error'       => mb_strimwidth(trim($error), 0, 500, '…'),
            'finished_at' => Carbon::now(),
        ])->save();
    }

    /** Human-readable outcome for the admin toast. */
    public function summary(): string
    {
        return match ($this->status) {
            self::STATUS_QUEUED     => 'Import queued — it will start in a moment.',
            self::STATUS_PROCESSING => "Importing… {$this->imported} added so far.",
            self::STATUS_FAILED     => 'Import failed: ' . ($this->error ?? 'unknown error'),
            default => $this->total === 0
                ? 'No valid email addresses found. Make sure the file has an "Email" column.'
                : "Imported {$this->imported} subscriber(s)"
                    . ($this->skipped > 0 ? ", skipped {$this->skipped} already on the list." : '.'),
        };
    }
}
