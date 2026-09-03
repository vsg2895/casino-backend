<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt to one receiver through one credential.
 *
 * The unique index (mailgun_key_id, mailgun_receiver_id, sent_on) is the
 * duplicate guard the brief asks for: a retried job or a second concurrent
 * worker inserting the same day's row is rejected by the database rather than
 * mailing the person twice. See the migration for why sent_on is a DATE.
 */
class MailgunReceiverSend extends Model
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_SKIPPED = 'skipped';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SKIPPED];

    protected $fillable = [
        'mailgun_key_id',
        'mailgun_receiver_id',
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
        return $this->belongsTo(MailgunKey::class, 'mailgun_key_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(MailgunReceiver::class, 'mailgun_receiver_id');
    }
}
