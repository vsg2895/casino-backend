<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Throwable;

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
     * Storage width of the `template` column, in characters.
     *
     * Published as a constant because the suite runs on SQLite, which does not
     * enforce VARCHAR length — an over-long key is stored happily there and only
     * fails on MySQL, in production. That is exactly how
     * `promotion_after_verification` (28) reached a varchar(20) and threw
     * SQLSTATE[22001]. A test asserts every EmailTemplateCatalog key fits this,
     * which is a check SQLite can actually perform.
     *
     * Kept in step with 2026_09_01_100000_widen_warmup_template_columns and with
     * `warmup_send_recipients.template`, which uses the same width.
     */
    public const int TEMPLATE_MAX_LENGTH = 40;

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
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_count' => 'integer',
            'cooldown_days'   => 'integer',
            'queued_count'    => 'integer',
            'cancelled_at'    => 'datetime',
        ];
    }

    /**
     * Whether this run has been stopped.
     *
     * Read by the fan-out before dispatching each batch, and by every batch
     * before it sends, so a stop takes effect on work already queued. A fresh
     * read rather than a loaded attribute: the job is deciding right now, and the
     * model it holds may be seconds stale.
     *
     * FAILS OPEN, deliberately. This runs on every batch, and `cancelled_at` is a
     * newer column than the code that reads it — during a deploy the workers can
     * be running ahead of `migrate`. Treating an unreadable flag as "not
     * cancelled" means warmup keeps behaving exactly as it did before the feature
     * existed; failing closed would halt every warmup batch on a schema lag or a
     * transient database blip, which is a far worse outcome than one run that
     * takes a moment longer to stop.
     *
     * Logged once per process, not per batch — a hundred identical lines would
     * bury the reason.
     */
    public static function isCancelled(int $id): bool
    {
        try {
            return static::query()->whereKey($id)->whereNotNull('cancelled_at')->exists();
        } catch (Throwable $e) {
            if (! self::$cancellationCheckFailed) {
                self::$cancellationCheckFailed = true;

                Log::warning('Warmup cancellation check unavailable; treating runs as active', [
                    'error' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    /** Guards the log line above so it is emitted once, not once per batch. */
    private static bool $cancellationCheckFailed = false;

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
