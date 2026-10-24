<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ManagesReceiverCampaign;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewReceiverMessageRequest;
use App\Http\Requests\Admin\SendSmtpCredentialTestRequest;
use App\Http\Requests\Admin\StoreSmtpCredentialRequest;
use App\Http\Requests\Admin\UpdateSmtpCredentialRequest;
use App\Http\Requests\Admin\UpdateSmtpReceiverSettingsRequest;
use App\Http\Resources\SmtpCredentialResource;
use App\Jobs\SendSmtpReceiverCampaignJob;
use App\Mail\SmtpCredentialTestMessage;
use App\Models\SmtpCredential;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\MailgunReceiverSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Throwable;

/**
 * Admin CRUD and campaign control for stored SMTP servers.
 *
 * Shaped after {@see MailgunKeyController}, against the SAME receiver list and
 * the SAME selector — the two channels differ only in how they authenticate.
 *
 * The one structural difference is that there is no scheduling here. A Mailgun
 * credential carries `send_enabled` because the scheduler decides when it runs;
 * these run only from {@see runReceiverCampaign()}, so no such flag exists and
 * nothing in this class reads one.
 */
class SmtpCredentialController extends Controller
{
    use ManagesReceiverCampaign;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SmtpCredential::query()->latest();

        if (in_array($request->query('status'), SmtpCredential::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        return SmtpCredentialResource::collection($query->get(SmtpCredential::LIST_COLUMNS));
    }

    public function store(StoreSmtpCredentialRequest $request): JsonResponse
    {
        $credential = SmtpCredential::create([
            'status' => SmtpCredential::STATUS_ACTIVE,
            ...$request->validated(),
        ]);

        return (new SmtpCredentialResource($credential))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateSmtpCredentialRequest $request,
        SmtpCredential $smtpCredential,
    ): SmtpCredentialResource {
        $validated = $request->validated();

        // A blank password field means "keep the stored one". The admin can
        // never read the current value, so requiring it on every edit would make
        // changing a port impossible without also retyping a secret.
        if (trim((string) ($validated['password'] ?? '')) === '') {
            unset($validated['password']);
        }

        $smtpCredential->update($validated);

        return new SmtpCredentialResource($smtpCredential);
    }

    /**
     * Delete a credential and its send history.
     *
     * Nothing else can reference one — there are no schedules on this channel —
     * so unlike {@see MailgunKeyController::destroy()} there is no in-use check
     * to make. `smtp_receiver_sends` cascades: history for a deleted credential
     * has no meaning, and the cross-channel daily claims are left alone because
     * they record that a person was mailed, not who mailed them.
     */
    public function destroy(SmtpCredential $smtpCredential): JsonResponse
    {
        $smtpCredential->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Flip active ⇄ inactive without touching the password. */
    public function toggle(SmtpCredential $smtpCredential): SmtpCredentialResource
    {
        $smtpCredential->update([
            'status' => $smtpCredential->isActive()
                ? SmtpCredential::STATUS_INACTIVE
                : SmtpCredential::STATUS_ACTIVE,
        ]);

        return new SmtpCredentialResource($smtpCredential);
    }

    /**
     * Send one test message through this credential.
     *
     * Goes through the real transport, not a simulated one — the whole point is
     * to find out whether these host/port/encryption settings authenticate. The
     * credential may be inactive: testing one you have just disabled, to find out
     * why it failed, has to work.
     *
     * What arrives is the credential's OWN campaign message, byte-identical to
     * what a run would deliver apart from the unsubscribe URL — see
     * {@see SmtpCredentialTestMessage}. Approving copy from a test that showed
     * something else is how the wrong email gets signed off.
     */
    public function test(
        SendSmtpCredentialTestRequest $request,
        SmtpCredential $smtpCredential,
        PromotionMailerFactory $mailers,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $mailer = $mailers->mailerForSmtpCredential($smtpCredential);

            $mailer->to($validated['to'])->send(
                new SmtpCredentialTestMessage(
                    $smtpCredential,
                    $validated['name'] ?? null,
                    // So `{{email}}` resolves in the test exactly as it will in
                    // a real send, instead of vanishing.
                    $validated['to'],
                ),
            );
        } catch (Throwable $e) {
            // The transport's own message is the useful part — "Connection could
            // not be established", "535 authentication failed" — so it is passed
            // through rather than replaced with a generic failure.
            return response()->json([
                'ok' => false,
                'message' => mb_strimwidth($e->getMessage(), 0, 500, '…'),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Test message sent to '.$validated['to'].'.',
        ]);
    }

    // ── Receiver campaign ────────────────────────────────────────────────────

    /**
     * Read this credential's receiver targeting, with a LIVE eligible count.
     *
     * There is no `send_enabled` key: this channel only sends when the admin
     * runs it, so its settings carry no switch.
     */
    public function receiverSettings(
        SmtpCredential $smtpCredential,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        return $this->receiverSettingsResponse(
            $smtpCredential,
            $selector,
            SendSmtpReceiverCampaignJob::blockedReason(...),
        );
    }

    /** Save the targeting rule and message for this credential. */
    public function updateReceiverSettings(
        UpdateSmtpReceiverSettingsRequest $request,
        SmtpCredential $smtpCredential,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        return $this->saveReceiverSettings(
            $request->validated(),
            $smtpCredential,
            $selector,
            SendSmtpReceiverCampaignJob::blockedReason(...),
        );
    }

    /** Re-seed the form from the source site's promotion template. */
    public function receiverTemplateSource(SmtpCredential $smtpCredential): JsonResponse
    {
        return $this->receiverTemplateSourceResponse();
    }

    /** Render the message as it would be sent, from unsaved fields. */
    public function previewReceiverMessage(PreviewReceiverMessageRequest $request, SmtpCredential $smtpCredential): JsonResponse
    {
        return $this->receiverMessagePreviewResponse($request->validated());
    }

    /** The exact receivers the next run would take. */
    public function previewReceiverBatch(
        SmtpCredential $smtpCredential,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        return $this->receiverBatchPreviewResponse($smtpCredential, $selector);
    }

    /**
     * Queue one run for this credential now.
     *
     * The only way this channel ever sends. Refuses through the job's own
     * {@see SendSmtpReceiverCampaignJob::blockedReason()}, so the admin sees the
     * same wording the log records.
     */
    public function runReceiverCampaign(
        SmtpCredential $smtpCredential,
        MailgunReceiverSelector $selector,
    ): JsonResponse {
        $reason = SendSmtpReceiverCampaignJob::blockedReason($smtpCredential);

        if ($reason !== null) {
            return response()->json(
                ['ok' => false, 'message' => ucfirst($reason).'.'],
                Response::HTTP_CONFLICT,
            );
        }

        // PRE-FLIGHT. Everything below this point happens on a queue worker,
        // where the admin sees nothing — so a run that would skip its entire
        // audience used to answer "Run queued." and then do nothing at all,
        // which is indistinguishable from a delivery. Checking here turns that
        // silence into a sentence naming the actual cause.
        $blocker = $selector->runBlocker($smtpCredential);

        if ($blocker !== null) {
            return response()->json(
                ['ok' => false, 'message' => 'Nothing to send: '.$blocker.'.'],
                Response::HTTP_CONFLICT,
            );
        }

        SendSmtpReceiverCampaignJob::dispatch($smtpCredential->id);

        return response()->json([
            'ok' => true,
            'message' => sprintf(
                'Run queued for %d receiver(s). Their "Sent" and "Last sent" update as each message goes out.',
                $selector->batchCount($smtpCredential),
            ),
        ]);
    }
}
