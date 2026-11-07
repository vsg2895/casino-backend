<?php

declare(strict_types=1);

namespace App\Models;

/** One spreadsheet imported into the Mailgun receiver list. */
class MailgunReceiverImport extends CampaignReceiverImport
{
    public function receiverModel(): string
    {
        return MailgunReceiver::class;
    }
}
