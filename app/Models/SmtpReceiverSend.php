<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt to one receiver through one SMTP credential.
 *
 * Twin of {@see MailgunReceiverSend}. Its unique index
 * (smtp_credential_id, mailgun_receiver_id, sent_on) guards this channel against
 * a retried job or a second worker mailing the same person twice in a day.
 *
 * The CROSS-channel guard is not here — it is `receiver_daily_claims`, which
 * both senders must win before they send.
 */
class SmtpReceiverSend extends Model
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_SKIPPED = 'skipped';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_SKIPPED];

    protected $fillable = [
        'smtp_credential_id',
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
        return $this->belongsTo(SmtpCredential::class, 'smtp_credential_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(MailgunReceiver::class, 'mailgun_receiver_id');
    }
}
