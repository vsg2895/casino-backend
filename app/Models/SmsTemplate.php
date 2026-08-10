<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable, admin-editable SMS message text.
 *
 * The point of the table is that the wording a bulk run sends is data rather than
 * something retyped per send: edit a template here and the next send starts from
 * the new text, with no deploy.
 *
 * A template is a starting point, not the payload. {@see \App\Jobs\SendSmsBatchJob}
 * transmits — and {@see PhoneSmsHistory} records — the body exactly as it stood in
 * the compose box when the run was queued, so editing a template afterwards can
 * never rewrite history or change a run already in flight.
 */
class SmsTemplate extends Model
{
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /**
     * A GSM-7 segment is 160 characters; the moment a message contains a
     * character outside that alphabet the WHOLE message is encoded as UCS-2 and a
     * segment drops to 70. Both numbers are Twilio's, and the difference is
     * billing: one stray curly quote can turn a 1-segment blast into 3.
     */
    public const int GSM_SEGMENT = 160;
    public const int UNICODE_SEGMENT = 70;

    protected $fillable = [
        'name',
        'body',
        'status',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Whether the body needs UCS-2 encoding.
     *
     * Approximated as "contains a non-ASCII character". Not exact — GSM-7 has a
     * few members outside ASCII and excludes a few inside it — but it errs
     * towards warning, which is the safe direction when the consequence is a
     * silently multiplied bill.
     */
    public function usesUnicode(): bool
    {
        return preg_match('/[^\x00-\x7F]/', (string) $this->body) === 1;
    }

    /** Characters per segment for this body's encoding. */
    public function segmentSize(): int
    {
        return $this->usesUnicode() ? self::UNICODE_SEGMENT : self::GSM_SEGMENT;
    }

    /**
     * The body's length in the units the carrier actually counts.
     *
     * NOT mb_strlen(). A UCS-2 message is measured in UTF-16 code units, so an
     * emoji outside the Basic Multilingual Plane occupies TWO of the 70 — while
     * mb_strlen() reports it as one character. Counting the naive way understates
     * a heavily-decorated message and would disagree with the live figure in the
     * editor, which is JavaScript `.length` and therefore already UTF-16.
     *
     * The two must agree: this value is what the templates list shows, and that
     * is the same number the admin watched while typing.
     */
    public function billedLength(): int
    {
        $body = (string) $this->body;

        if ($body === '') {
            return 0;
        }

        if (! $this->usesUnicode()) {
            return strlen($body); // pure ASCII: bytes, characters and units agree
        }

        $utf16 = mb_convert_encoding($body, 'UTF-16LE', 'UTF-8');

        return (int) (strlen($utf16) / 2);
    }

    /** How many billable segments this body costs per recipient. */
    public function segments(): int
    {
        $length = $this->billedLength();

        return $length === 0 ? 0 : (int) ceil($length / $this->segmentSize());
    }

    /** A one-line preview for the listing, so a long body never breaks the table. */
    public function preview(int $length = 80): string
    {
        $body = trim(preg_replace('/\s+/', ' ', (string) $this->body) ?? '');

        return mb_strimwidth($body, 0, $length, '…');
    }
}
