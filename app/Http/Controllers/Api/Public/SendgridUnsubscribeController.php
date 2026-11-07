<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Models\SendgridReceiver;

/**
 * One-click unsubscribe for the SendGrid receiver list.
 *
 * The Mailgun flow against the SendGrid table — POST acts (RFC 8058), GET only
 * renders a confirm page, and the opt-out lands on the SHARED suppression list,
 * so the address stops on both lists. See {@see MailgunUnsubscribeController}.
 */
class SendgridUnsubscribeController extends MailgunUnsubscribeController
{
    protected function receiverModel(): string
    {
        return SendgridReceiver::class;
    }
}
