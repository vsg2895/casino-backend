<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/**
 * Add one address to the SendGrid receiver list.
 *
 * The Mailgun rules against the SendGrid table: an address may sit on both
 * lists, so uniqueness is checked within this list only.
 */
class StoreSendgridReceiverRequest extends StoreMailgunReceiverRequest
{
    protected function receiverTable(): string
    {
        return 'sendgrid_receivers';
    }
}
