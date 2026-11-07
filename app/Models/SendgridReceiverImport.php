<?php

declare(strict_types=1);

namespace App\Models;

/** One spreadsheet imported into the SendGrid receiver list. */
class SendgridReceiverImport extends CampaignReceiverImport
{
    public function receiverModel(): string
    {
        return SendgridReceiver::class;
    }
}
