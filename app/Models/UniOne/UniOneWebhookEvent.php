<?php

declare(strict_types=1);

namespace App\Models\UniOne;

use Illuminate\Database\Eloquent\Model;

/** One ingested delivery event. */
class UniOneWebhookEvent extends Model
{
    public $timestamps = false;

    protected $table = 'unione_webhook_events';

    /**
     * Event status → receiver status.
     *
     * Deliberately routed through UniOneReceiver::FAILURE_STATUS_MAP semantics
     * so the webhook and the synchronous `failed_emails` path can never disagree
     * about what an outcome means.
     *
     * `sent`, `delivered`, `opened` and `clicked` are absent: they are good news
     * and change no state on the row.
     *
     * @var array<string, string>
     */
    public const array STATUS_MAP = [
        'hard_bounced' => UniOneReceiver::STATUS_BOUNCED,
        'spam'         => UniOneReceiver::STATUS_COMPLAINED,
        'unsubscribed' => UniOneReceiver::STATUS_UNSUBSCRIBED,
        // soft_bounced is a temporary failure — handled like
        // temporary_unavailable, keeping the row active with a retry_after.
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload'    => 'array',
            'applied'    => 'boolean',
            'event_time' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The dedup identity.
     *
     * UniOne sends no event id, so identity is composed from the five fields
     * that distinguish one real event from another. A genuine second open has a
     * different `event_time` and still counts, which is correct.
     */
    public static function hashFor(string $eventName, ?string $jobId, ?string $email, ?string $status, ?string $eventTime): string
    {
        return hash('sha256', implode('|', [$eventName, $jobId ?? '', $email ?? '', $status ?? '', $eventTime ?? '']));
    }
}
