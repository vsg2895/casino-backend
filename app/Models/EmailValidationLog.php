<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Support\Validation\ValidationReason;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One SendGrid address-validation attempt.
 *
 * Append-only from the application's point of view: nothing updates or deletes a
 * row except the prune command. The admin surface over it is read-only, with no
 * re-run action — re-running would spend a credit from an audit screen.
 */
class EmailValidationLog extends Model
{
    /**
     * Reasons that never reached SendGrid, and so never cost a credit.
     *
     * @var list<string>
     */
    public const array UNBILLED_REASONS = [
        ValidationReason::MISSING_KEY,
        ValidationReason::QUOTA_EXHAUSTED,
        ValidationReason::EMAIL_COOLDOWN,
        ValidationReason::DISABLED,
        ValidationReason::PENDING_RESEND,
    ];

    /** @var list<string> The six checks, as real indexed columns. */
    public const array CHECK_COLUMNS = [
        'has_valid_address_syntax',
        'has_mx_or_a_record',
        'is_suspected_disposable_address',
        'is_suspected_role_address',
        'has_known_bounces',
        'has_suspected_bounces',
    ];

    public const string VERDICT_VALID = 'Valid';
    public const string VERDICT_RISKY = 'Risky';
    public const string VERDICT_INVALID = 'Invalid';

    /** @var list<string> */
    public const array VERDICTS = [self::VERDICT_VALID, self::VERDICT_RISKY, self::VERDICT_INVALID];

    protected $fillable = [
        'site_id', 'email', 'verdict', 'score', 'suggestion', 'source',
        'outcome', 'reason_code',
        ...self::CHECK_COLUMNS,
        'raw_checks', 'was_cached',
        'http_status', 'error_message', 'latency_ms', 'quota_month',
    ];

    protected function casts(): array
    {
        return [
            'raw_checks'  => 'array',
            'score'       => 'float',
            'was_cached'  => 'boolean',
            'http_status' => 'integer',
            'latency_ms'  => 'integer',
            ...array_fill_keys(self::CHECK_COLUMNS, 'boolean'),
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Rows that consumed a SendGrid credit.
     *
     * The ONLY definition of "a real call" in the codebase — the quota counter,
     * the stats screen and the prune report all defer to it, so they can never
     * disagree about what the month has cost.
     *
     * @param  Builder<EmailValidationLog>  $query
     * @return Builder<EmailValidationLog>
     */
    public function scopeBilled(Builder $query): Builder
    {
        // A cached verdict and a skip both cost nothing.
        //
        // Stated EXPLICITLY as a reason-code list rather than inferred from
        // whether an http_status came back. Inference looked equivalent and was
        // not: any row written without a status — a fixture, a future code path,
        // a partial failure — would silently drop out of the quota count, and a
        // quota that under-reports is the one failure mode this whole feature
        // exists to prevent.
        return $query->where('was_cached', false)
            ->where(function (Builder $q): void {
                $q->whereNull('reason_code')->orWhereNotIn('reason_code', self::UNBILLED_REASONS);
            });
    }

    /** The calendar month (UTC) a call is billed to. */
    public static function currentQuotaMonth(): string
    {
        return now('UTC')->format('Y-m');
    }
}
