<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\StoreSendgridReceiverRequest;
use App\Http\Requests\Admin\UpdateSendgridReceiverRequest;
use App\Http\Resources\MailgunReceiverResource;
use App\Jobs\ImportSendgridReceiversJob;
use App\Models\SendgridReceiver;
use App\Models\SendgridReceiverImport;
use Illuminate\Http\JsonResponse;

/**
 * Admin CRUD for the SendGrid receiver list — Custom Receivers → Sendgrid
 * Receivers. Mailed by SendGrid keys only.
 *
 * Same contract, same filters and the same response shape as the Mailgun list
 * ({@see ReceiverListController}); a separate table, so neither list's rows,
 * counters or imports ever show up in the other.
 */
class SendgridReceiverController extends ReceiverListController
{
    public function store(StoreSendgridReceiverRequest $request): JsonResponse
    {
        return $this->storeReceiver($request->validated());
    }

    public function update(
        UpdateSendgridReceiverRequest $request,
        SendgridReceiver $sendgridReceiver,
    ): MailgunReceiverResource {
        return $this->updateReceiver($sendgridReceiver, $request->validated());
    }

    public function destroy(SendgridReceiver $sendgridReceiver): JsonResponse
    {
        return $this->destroyReceiver($sendgridReceiver);
    }

    /** Poll target for a queued import. */
    public function importStatus(SendgridReceiverImport $sendgridReceiverImport): JsonResponse
    {
        return response()->json(['data' => $sendgridReceiverImport]);
    }

    protected function receiverModel(): string
    {
        return SendgridReceiver::class;
    }

    protected function importModel(): string
    {
        return SendgridReceiverImport::class;
    }

    protected function dispatchImport(int $importId): void
    {
        ImportSendgridReceiversJob::dispatch($importId);
    }

    protected function importDirectory(): string
    {
        return 'sendgrid-receiver-imports';
    }
}
