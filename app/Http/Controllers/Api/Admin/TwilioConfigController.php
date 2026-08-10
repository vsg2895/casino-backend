<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendTwilioTestRequest;
use App\Http\Requests\Admin\StoreTwilioConfigRequest;
use App\Http\Requests\Admin\UpdateTwilioConfigRequest;
use App\Http\Resources\TwilioConfigResource;
use App\Models\PhoneSmsHistory;
use App\Models\TwilioConfig;
use App\Services\Sms\TwilioSmsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin CRUD for stored Twilio credentials used by bulk SMS sends.
 *
 * Deliberately the same contract as {@see SendgridKeyController} and
 * {@see MailgunKeyController} — index / store / update / destroy / toggle / test —
 * so the admin panel's credential screens are interchangeable and an operator
 * learns one pattern rather than three.
 *
 * The Auth Token is write-only: accepted on create/update, never returned (the
 * Resource exposes only a masked preview). Deleting a configuration nulls it out
 * on any history row that referenced it (FK nullOnDelete), so the record of what
 * was sent survives the credential being removed.
 */
class TwilioConfigController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TwilioConfig::query()->latest();

        // Optional ?status=active filter (used by the send dialog's dropdown).
        if (in_array($request->query('status'), TwilioConfig::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }

        return TwilioConfigResource::collection($query->get());
    }

    public function store(StoreTwilioConfigRequest $request): JsonResponse
    {
        $config = TwilioConfig::create([
            'status' => TwilioConfig::STATUS_ACTIVE,
            ...$request->validated(),
        ]);

        return (new TwilioConfigResource($config))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateTwilioConfigRequest $request, TwilioConfig $twilioConfig): TwilioConfigResource
    {
        // auth_token is only present when the admin actually typed a new one, so a
        // blank edit preserves the stored token (see the Form Request).
        $twilioConfig->update($request->validated());

        return new TwilioConfigResource($twilioConfig);
    }

    public function destroy(TwilioConfig $twilioConfig): JsonResponse
    {
        $twilioConfig->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Flip active ⇄ inactive without touching the token. */
    public function toggle(TwilioConfig $twilioConfig): TwilioConfigResource
    {
        $twilioConfig->update([
            'status' => $twilioConfig->isActive()
                ? TwilioConfig::STATUS_INACTIVE
                : TwilioConfig::STATUS_ACTIVE,
        ]);

        return new TwilioConfigResource($twilioConfig);
    }

    /**
     * Send ONE real SMS through this stored credential to prove it works.
     *
     * The counterpart of the SendGrid / Mailgun key tests, and it exists for the
     * same reason: a bulk run is the worst possible place to discover that a token
     * is wrong, that the sending number is not owned by the account, or that the
     * destination country is not enabled on it (Twilio's geo permissions catch a
     * lot of first-time users). Better to spend one message finding out.
     *
     * Runs synchronously and surfaces Twilio's own message on failure, so the
     * admin gets a definitive works / does-not-work answer with the actual reason.
     * Inactive configurations can be tested too: verifying a credential before
     * enabling it is a legitimate workflow.
     *
     * The attempt is recorded in the send history like any other, so a test that
     * "worked but never arrived" can still be looked up by its message SID in the
     * Twilio console.
     */
    public function test(
        SendTwilioTestRequest $request,
        TwilioConfig $twilioConfig,
        TwilioSmsClient $client,
    ): JsonResponse {
        $to = (string) $request->validated('to');
        $body = trim((string) $request->validated('body'));

        try {
            $result = $client->send($twilioConfig, $to, $body);
        } catch (Throwable $e) {
            // A run-wide fault (bad credentials, no sender) — exactly what the
            // test is for.
            Log::warning('Twilio configuration test failed', [
                'twilio_config_id' => $twilioConfig->id,
                'error'            => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Test failed: ' . $e->getMessage(),
            ], Response::HTTP_BAD_GATEWAY);
        }

        $this->recordTest($twilioConfig, $to, $body, $result);

        if (! $result->ok) {
            Log::warning('Twilio configuration test was rejected', [
                'twilio_config_id' => $twilioConfig->id,
                'error_code'       => $result->errorCode,
                'error'            => $result->error,
            ]);

            return response()->json([
                'ok'         => false,
                'error_code' => $result->errorCode,
                'message'    => 'Twilio rejected the test: ' . ($result->error ?? 'unknown error'),
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'ok'          => true,
            'message_sid' => $result->messageSid,
            'message'     => sprintf(
                'Test message sent to %s from %s using "%s".',
                $to,
                $twilioConfig->senderLabel(),
                $twilioConfig->name,
            ),
        ]);
    }

    /**
     * Record the test in the send history, so it is auditable like any other send.
     *
     * Never allowed to fail the request: the message has already gone out, and
     * reporting a failure the admin cannot act on would be misleading.
     */
    private function recordTest(
        TwilioConfig $config,
        string $to,
        string $body,
        \App\Services\Sms\SmsSendResult $result,
    ): void {
        try {
            PhoneSmsHistory::create([
                'phone'            => $to,
                'twilio_config_id' => $config->id,
                'message_sid'      => $result->messageSid,
                'status'           => $result->status(),
                'error_code'       => $result->errorCode,
                'error'            => $result->error === null
                    ? null
                    : mb_strimwidth($result->error, 0, 500, '…'),
                'body'             => $body,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record a Twilio test in the SMS history', [
                'twilio_config_id' => $config->id,
                'error'            => $e->getMessage(),
            ]);
        }
    }
}
