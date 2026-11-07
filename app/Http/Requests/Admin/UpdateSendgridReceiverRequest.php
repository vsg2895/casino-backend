<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

/** Edit one address on the SendGrid receiver list — see StoreSendgridReceiverRequest. */
class UpdateSendgridReceiverRequest extends UpdateMailgunReceiverRequest
{
    protected function receiverTable(): string
    {
        return 'sendgrid_receivers';
    }

    protected function routeParameter(): string
    {
        return 'sendgrid_receiver';
    }
}
