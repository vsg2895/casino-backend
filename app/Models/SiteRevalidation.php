<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to tell a site its content changed.
 *
 * Immutable — hence `UPDATED_AT = null`. An attempt happened or it did not; the
 * row is never revised.
 */
class SiteRevalidation extends Model
{
    public const string STATUS_SUCCESS = 'success';
    public const string STATUS_FAILED = 'failed';

    public const string TRIGGER_OBSERVER = 'observer';
    public const string TRIGGER_MANUAL = 'manual';

    public const ?string UPDATED_AT = null;

    protected $fillable = [
        'site_id', 'tags', 'status', 'http_status', 'error', 'duration_ms', 'triggered_by',
    ];

    protected function casts(): array
    {
        return [
            'tags'        => 'array',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at'  => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
