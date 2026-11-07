<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\StoreMailgunReceiverRequest;
use App\Http\Requests\Admin\UpdateMailgunReceiverRequest;
use App\Http\Resources\MailgunReceiverResource;
use App\Jobs\ImportMailgunReceiversJob;
use App\Models\MailgunReceiver;
use App\Models\MailgunReceiverImport;
use Illuminate\Http\JsonResponse;

/**
 * Admin CRUD for the Mailgun receiver list — Custom Receivers → Mailgun
 * Receivers. Mailed by Mailgun credentials and stored SMTP servers.
 *
 * The listing, count, import, bulk delete, reset and source counts are
 * {@see ReceiverListController}'s, shared with the SendGrid list. What stays
 * here is what binds a route parameter to this list's table.
 */
class MailgunReceiverController extends ReceiverListController
{
    public function store(StoreMailgunReceiverRequest $request): JsonResponse
    {
        return $this->storeReceiver($request->validated());
    }

    public function update(
        UpdateMailgunReceiverRequest $request,
        MailgunReceiver $mailgunReceiver,
    ): MailgunReceiverResource {
        return $this->updateReceiver($mailgunReceiver, $request->validated());
    }

    public function destroy(MailgunReceiver $mailgunReceiver): JsonResponse
    {
        return $this->destroyReceiver($mailgunReceiver);
    }

    /** Poll target for a queued import. */
    public function importStatus(MailgunReceiverImport $mailgunReceiverImport): JsonResponse
    {
        return response()->json(['data' => $mailgunReceiverImport]);
    }

    protected function receiverModel(): string
    {
        return MailgunReceiver::class;
    }

    protected function importModel(): string
    {
        return MailgunReceiverImport::class;
    }

    protected function dispatchImport(int $importId): void
    {
        ImportMailgunReceiversJob::dispatch($importId);
    }

    protected function importDirectory(): string
    {
        return 'mailgun-receiver-imports';
    }
}
