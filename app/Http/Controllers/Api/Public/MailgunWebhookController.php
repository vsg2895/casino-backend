<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\MailgunKey;
use App\Models\MailgunReceiver;
use App\Models\MailgunSuppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ingests Mailgun delivery events: permanent failures, complaints, unsubscribes.
 *
 * This is what keeps a 100k list from degrading. Without it, hard bounces stay
 * in the pool and are re-sent every run, which is the fastest way to have a
 * sending domain blocked.
 *
 * Signature verification is mandatory. The endpoint is public and unauthenticated
 * by necessity — Mailgun sends no bearer token — so the HMAC is the ONLY thing
 * distinguishing a real event from anyone who knows the URL. Without it this
 * route would let a stranger suppress arbitrary addresses.
 */
class MailgunWebhookController extends Controller
{
    /** Events that permanently retire an address. */
    private const array SUPPRESSING = [
        'failed'      => MailgunSuppression::REASON_BOUNCE,
        'complained'  => MailgunSuppression::REASON_COMPLAINT,
        'unsubscribed' => MailgunSuppression::REASON_UNSUBSCRIBE,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $signature = (array) $request->input('signature', []);
        $payload = (array) $request->input('event-data', []);

        if (! $this->verify($signature)) {
            // 406 rather than 401: Mailgun retries 4xx except 406, and a
            // signature that does not verify will never verify on a retry.
            return response()->json(['ok' => false], Response::HTTP_NOT_ACCEPTABLE);
        }

        $event = (string) ($payload['event'] ?? '');
        $email = (string) ($payload['recipient'] ?? '');
        $severity = (string) ($payload['severity'] ?? '');

        // A 'failed' event with severity 'temporary' is a soft bounce — a full
        // mailbox, a greylist. Suppressing on those would retire people who are
        // perfectly reachable tomorrow.
        if ($event === 'failed' && $severity !== 'permanent') {
            return response()->json(['ok' => true, 'ignored' => 'temporary failure']);
        }

        if ($email === '' || ! isset(self::SUPPRESSING[$event])) {
            return response()->json(['ok' => true, 'ignored' => $event]);
        }

        try {
            DB::transaction(static function () use ($email, $event): void {
                MailgunSuppression::suppress(
                    $email,
                    self::SUPPRESSING[$event],
                    'Mailgun webhook: ' . $event,
                );

                MailgunReceiver::where('email', mb_strtolower(trim($email)))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::warning('Mailgun webhook processing failed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            // 500 so Mailgun retries — the event is real and worth not losing.
            return response()->json(['ok' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Verify Mailgun's HMAC over "{timestamp}{token}".
     *
     * Checked against every stored credential's key, because the webhook does
     * not say which credential it belongs to. hash_equals, not ===, so the
     * comparison is not a timing oracle.
     */
    private function verify(array $signature): bool
    {
        $timestamp = (string) ($signature['timestamp'] ?? '');
        $token = (string) ($signature['token'] ?? '');
        $provided = (string) ($signature['signature'] ?? '');

        if ($timestamp === '' || $token === '' || $provided === '') {
            return false;
        }

        // Reject anything older than 15 minutes: without this a captured
        // payload could be replayed indefinitely.
        if (abs(time() - (int) $timestamp) > 900) {
            return false;
        }

        foreach (MailgunKey::query()->get(['id', 'api_key']) as $credential) {
            try {
                $key = (string) $credential->api_key;
            } catch (Throwable) {
                continue; // undecryptable key — skip, never fail the request
            }

            if ($key === '') {
                continue;
            }

            if (hash_equals(hash_hmac('sha256', $timestamp . $token, $key), $provided)) {
                return true;
            }
        }

        return false;
    }
}
