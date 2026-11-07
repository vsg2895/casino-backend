<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One address reachable through a stored Mailgun credential or a stored SMTP
 * server — the Mailgun half of Custom Receivers.
 *
 * Sibling of {@see Newsletter} in shape only — this pool is global rather than
 * per-site, and nothing here reads or writes the newsletter tables. Its twin
 * list is {@see SendgridReceiver}; the two never share a row.
 *
 * The batch predicate (sendable, not suppressed, cooldown, today's claim) lives
 * on {@see CampaignReceiver}, shared with the SendGrid list, so the preview
 * count, the preview listing and the sending job compose the same scopes on
 * both lists. Same discipline as {@see WarmupRecipientService}.
 */
class MailgunReceiver extends CampaignReceiver
{
    use HasFactory;

    public static function receiverForeignKey(): string
    {
        return 'mailgun_receiver_id';
    }

    /** Shared by the Mailgun and SMTP channels: one email per address per day across both. */
    public static function dailyClaimsTable(): string
    {
        return 'receiver_daily_claims';
    }

    public static function unsubscribePath(): string
    {
        return 'mailgun-unsubscribe';
    }
}
