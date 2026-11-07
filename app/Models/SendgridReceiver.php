<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One address reachable through a stored SendGrid key — the SendGrid half of
 * Custom Receivers.
 *
 * A separate list from {@see MailgunReceiver}: its own table, its own daily
 * claims and its own send history, so the same person can sit on both lists
 * and each list decides on its own when to mail them. The only thing the two
 * share is the suppression list (see {@see CampaignReceiver::scopeNotSuppressed()}):
 * an opt-out or a hard bounce on either one stops both.
 */
class SendgridReceiver extends CampaignReceiver
{
    use HasFactory;

    public static function receiverForeignKey(): string
    {
        return 'sendgrid_receiver_id';
    }

    public static function dailyClaimsTable(): string
    {
        return 'sendgrid_receiver_daily_claims';
    }

    public static function unsubscribePath(): string
    {
        return 'sendgrid-unsubscribe';
    }
}
