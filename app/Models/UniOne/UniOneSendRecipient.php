<?php

declare(strict_types=1);

namespace App\Models\UniOne;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-address outcome of one send. */
class UniOneSendRecipient extends Model
{
    protected $table = 'unione_send_recipients';

    public const string STATUS_ACCEPTED = 'accepted';
    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    public function send(): BelongsTo
    {
        return $this->belongsTo(UniOneSend::class, 'unione_send_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(UniOneReceiver::class, 'unione_receiver_id');
    }
}
