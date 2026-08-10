<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded SMS send attempt — the per-recipient result of a bulk run.
 *
 * The brief's "record the result for each phone number" lands here: what was
 * sent, through which credential, whether Twilio accepted it, and the exact
 * failure when it did not. Because a batch never aborts on one bad number, this
 * table is the only place a partial failure is visible afterwards.
 *
 * `phone` is a plain string, not a foreign key: the audit records what was sent
 * and must outlive the number being removed from the list. Same choice, for the
 * same reason, as {@see PromotionEmailHistory} storing an address.
 */
class PhoneSmsHistory extends Model
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SENT, self::STATUS_FAILED];

    /**
     * Twilio's code for "this number has opted out of receiving messages".
     *
     * Worth naming rather than inlining: it is the one failure that must change
     * stored state (the number is flagged so later runs skip it) instead of just
     * being logged. See {@see \App\Jobs\SendSmsBatchJob}.
     */
    public const int ERROR_OPTED_OUT = 21610;

    protected $fillable = [
        'phone',
        'twilio_config_id',
        'message_sid',
        'status',
        'error_code',
        'error',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'twilio_config_id' => 'integer',
            'error_code'       => 'integer',
        ];
    }

    public function twilioConfig(): BelongsTo
    {
        return $this->belongsTo(TwilioConfig::class);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Search by number, matching {@see NewsletterBasedOnPhone::scopeSearch()} so
     * the two listings behave the same way for the same input.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $prefixSearch = str_starts_with($term, '+');
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        if ($digits === '') {
            return $query;
        }

        return $prefixSearch
            ? $query->where('phone', 'like', '+' . $digits . '%')
            : $query->where('phone', 'like', '%' . $digits . '%');
    }
}
