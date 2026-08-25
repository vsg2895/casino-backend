<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One warmup run — which site's template went out, to how many addresses, under
 * which cooldown.
 *
 * The per-send parameters live here rather than on {@see WarmupEmail} because
 * they describe the REQUEST, not the address: putting site/template/count on the
 * address table would rewrite every row on every run.
 *
 * What each individual address received is on {@see WarmupSendRecipient}. This
 * row is the header; that table is the detail.
 */
class WarmupSend extends Model
{
    /**
     * Cooldown bounds, in days.
     *
     * Declared here so the Form Request rule, the API's advertised maximum and
     * the admin's number input all read ONE source. 365 is the ceiling because a
     * cooldown longer than a year is indistinguishable from "never re-send", for
     * which the correct action is removing the address from the list.
     */
    public const int MIN_COOLDOWN_DAYS = 1;
    public const int MAX_COOLDOWN_DAYS = 365;

    protected $fillable = [
        'site_id',
        'user_id',
        'template',
        'requested_count',
        'cooldown_days',
        'queued_count',
    ];

    protected function casts(): array
    {
        return [
            'requested_count' => 'integer',
            'cooldown_days'   => 'integer',
            'queued_count'    => 'integer',
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

    /** Every address this run attempted, with its per-address outcome. */
    public function recipients(): HasMany
    {
        return $this->hasMany(WarmupSendRecipient::class);
    }

    /** Whether the admin asked for the whole list rather than a fixed number. */
    public function targetsEveryone(): bool
    {
        return $this->requested_count === null;
    }
}
