<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Phone\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One phone number on the SMS notification list.
 *
 * STANDALONE. No site_id, no client_id, no relationships — see the
 * create_newsletters_based_on_phone migration for the column-by-column reasoning
 * behind what this table does and does not carry. Nothing in this feature reads
 * {@see Newsletter} or any client data, and nothing here is reachable from them.
 *
 * The table name is set explicitly because it is not the plural of the class
 * name: the brief names the table `newsletters_based_on_phone`, and honouring
 * that exactly matters more than the convention.
 */
class NewsletterBasedOnPhone extends Model
{
    /** Not derivable from the class name — the brief fixes this name. */
    protected $table = 'newsletters_based_on_phone';

    protected $fillable = [
        'phone',
        'opted_out',
        'opted_out_at',
    ];

    protected function casts(): array
    {
        return [
            'opted_out'    => 'boolean',
            'opted_out_at' => 'datetime',
        ];
    }

    /**
     * Numbers eligible to receive a bulk send.
     *
     * The SMS counterpart of the `unsubscribes` exclusion in
     * {@see \App\Services\ScheduleRecipientService::audience()}. Every audience
     * query starts here — no caller may rebuild it, because "did this person say
     * STOP" is a compliance question, not a filter the admin gets to switch off.
     */
    public function scopeSubscribed(Builder $query): Builder
    {
        return $query->where('opted_out', false);
    }

    /**
     * Search by number.
     *
     * Two shapes, because they have very different costs. A term beginning with
     * "+" is treated as a prefix and served by the unique index. Anything else is
     * matched as "contains" against the digits, which cannot use the index and
     * scans — accepted deliberately, because an admin looking for a number
     * usually knows its tail, not its country code, and a search that only worked
     * on full E.164 input would be useless to them.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $prefixSearch = str_starts_with($term, '+');

        // Reduce to digits so the term matches how numbers are STORED: the admin
        // may type "(555) 010-0199" but the column holds "+15550100199".
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        if ($digits === '') {
            return $query;
        }

        return $prefixSearch
            ? $query->where('phone', 'like', '+' . $digits . '%')
            : $query->where('phone', 'like', '%' . $digits . '%');
    }

    /** Mark this number as opted out (Twilio reported STOP). Idempotent. */
    public function markOptedOut(): void
    {
        if ($this->opted_out) {
            return;
        }

        $this->forceFill([
            'opted_out'    => true,
            'opted_out_at' => Carbon::now(),
        ])->save();
    }

    /** The number partially masked, for logs where the full value is not needed. */
    public function maskedPhone(): string
    {
        return PhoneNumber::mask((string) $this->phone);
    }
}
