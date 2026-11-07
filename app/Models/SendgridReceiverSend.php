<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt to one SendGrid receiver through one SendGrid key.
 *
 * Twin of {@see MailgunReceiverSend}. Its unique index
 * (sendgrid_key_id, sendgrid_receiver_id, sent_on) guards this channel against a
 * retried job mailing the same person twice in a day; the guard across every
 * SendGrid key is `sendgrid_receiver_daily_claims`, which the sender must win
 * first.
 */
class SendgridReceiverSend extends Model
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_SKIPPED = 'skipped';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SKIPPED];

    protected $fillable = [
        'sendgrid_key_id',
        'sendgrid_receiver_id',
        'email',
        'status',
        'error',
        'sent_at',
        'sent_on',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'sent_on' => 'date'];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(SendgridKey::class, 'sendgrid_key_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(SendgridReceiver::class, 'sendgrid_receiver_id');
    }
}
