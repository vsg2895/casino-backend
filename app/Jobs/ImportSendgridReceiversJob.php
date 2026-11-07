<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SendgridReceiverImport;

/**
 * Parses an uploaded receiver list into `sendgrid_receivers`.
 *
 * The Mailgun import job with a different import row — same queue, same
 * timeout, same single attempt, same cleanup of the staged file. See
 * {@see ImportMailgunReceiversJob} for why each of those is what it is.
 */
class ImportSendgridReceiversJob extends ImportMailgunReceiversJob
{
    protected function importModel(): string
    {
        return SendgridReceiverImport::class;
    }
}
