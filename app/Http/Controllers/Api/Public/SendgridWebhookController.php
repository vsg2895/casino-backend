<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\MailgunSuppression;
use App\Models\SendgridKey;
use App\Models\SendgridReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ingests SendGrid Event Webhook deliveries: hard bounces, spam reports and
 * unsubscribes, onto the SHARED suppression list.
 *
 * The SendGrid twin of {@see MailgunWebhookController}, and the reason the
 * SendGrid receiver list does not degrade: the SendGrid API accepts a message
 * and bounces it LATER, so the send loop's own hard-bounce rule almost never
 * fires for this channel. Without these events a dead address would be re-sent
 * every run.
 *
 * Signature verification is mandatory. The endpoint is public by necessity, so
 * the Signed Event Webhook's ECDSA signature over `timestamp . raw body` is the
 * ONLY thing distinguishing SendGrid from anyone who knows the URL — without it
 * a stranger could suppress arbitrary addresses. The verification key is a
 * PUBLIC key stored per SendGrid key (each account signs with its own); an
 * event is accepted when any stored key verifies it, exactly as the Mailgun
 * webhook tries every stored credential.
 *
 * No timestamp age check, unlike Mailgun: SendGrid keeps retrying a failed
 * delivery for hours, and a short window would drop exactly those retries. A replay can
 * only re-suppress an address SendGrid really reported, which is idempotent.
 */
class SendgridWebhookController extends Controller
{
    public const string SIGNATURE_HEADER = 'X-Twilio-Email-Event-Webhook-Signature';

    public const string TIMESTAMP_HEADER = 'X-Twilio-Email-Event-Webhook-Timestamp';

    /** Events that permanently retire an address, whatever else they carry. */
    private const array SUPPRESSING = [
        'spamreport'        => MailgunSuppression::REASON_COMPLAINT,
        'unsubscribe'       => MailgunSuppression::REASON_UNSUBSCRIBE,
        'group_unsubscribe' => MailgunSuppression::REASON_UNSUBSCRIBE,
    ];

    /**
     * `dropped` reasons that mean the address itself is dead to SendGrid.
     *
     * Matched as lowercase substrings of the event's `reason`. Anything else a
     * drop can say — an invalid header, a content filter — is about the message,
     * not the person, and must not retire them.
     */
    private const array DROPPED_REASONS = [
        'bounced address'       => MailgunSuppression::REASON_BOUNCE,
        'spam reporting address' => MailgunSuppression::REASON_COMPLAINT,
        'unsubscribed address'  => MailgunSuppression::REASON_UNSUBSCRIBE,
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $body = (string) $request->getContent();

        if (! $this->verify(
            (string) $request->header(self::SIGNATURE_HEADER, ''),
            (string) $request->header(self::TIMESTAMP_HEADER, ''),
            $body,
        )) {
            return response()->json(['ok' => false], Response::HTTP_FORBIDDEN);
        }

        $events = json_decode($body, true);

        if (! is_array($events) || ! array_is_list($events)) {
            // Signed by SendGrid yet not a batch: nothing a retry would fix.
            return response()->json(['ok' => true, 'ignored' => 'not an event batch']);
        }

        /** @var array<string, array{0: string, 1: string}> $retire email => [reason, detail] */
        $retire = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $email = Str::lower(trim((string) ($event['email'] ?? '')));
            $reason = $this->suppressionReason($event);

            if ($email === '' || $reason === null) {
                continue;
            }

            // Last word per address wins within a batch; any of them suppresses.
            $retire[$email] = [$reason, 'SendGrid webhook: ' . (string) ($event['event'] ?? '')];
        }

        if ($retire === []) {
            return response()->json(['ok' => true, 'suppressed' => 0]);
        }

        try {
            DB::transaction(static function () use ($retire): void {
                foreach ($retire as $email => [$reason, $detail]) {
                    MailgunSuppression::suppress($email, $reason, $detail);
                }

                SendgridReceiver::query()
                    ->whereIn('email', array_keys($retire))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            });
        } catch (Throwable $e) {
            Log::warning('SendGrid webhook processing failed', [
                'events' => count($events),
                'error'  => $e->getMessage(),
            ]);

            // 500 so SendGrid retries — the events are real and worth not losing.
            return response()->json(['ok' => false], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['ok' => true, 'suppressed' => count($retire)]);
    }

    /**
     * Why this event retires its address, or null when it does not.
     *
     * A `bounce` of type `blocked` is SendGrid's soft bounce — a receiving
     * server refusing for now (reputation, rate, greylisting). Suppressing on it
     * would retire people who are reachable tomorrow, the same trap the Mailgun
     * webhook avoids with `severity`.
     *
     * @param  array<string, mixed>  $event
     */
    private function suppressionReason(array $event): ?string
    {
        $name = (string) ($event['event'] ?? '');

        if ($name === 'bounce') {
            return (string) ($event['type'] ?? 'bounce') === 'blocked'
                ? null
                : MailgunSuppression::REASON_BOUNCE;
        }

        if ($name === 'dropped') {
            $why = Str::lower((string) ($event['reason'] ?? ''));

            foreach (self::DROPPED_REASONS as $needle => $reason) {
                if (str_contains($why, $needle)) {
                    return $reason;
                }
            }

            return null;
        }

        return self::SUPPRESSING[$name] ?? null;
    }

    /**
     * Verify SendGrid's ECDSA signature over `timestamp . raw body` against
     * every stored verification key.
     */
    private function verify(string $signature, string $timestamp, string $body): bool
    {
        if ($signature === '' || $timestamp === '') {
            return false;
        }

        $decoded = base64_decode($signature, true);

        if ($decoded === false || $decoded === '') {
            return false;
        }

        $keys = SendgridKey::query()
            ->whereNotNull('webhook_verification_key')
            ->pluck('webhook_verification_key');

        foreach ($keys as $key) {
            $publicKey = openssl_pkey_get_public(self::toPem((string) $key));

            // A key that does not parse verifies nothing — skip it, never fail
            // the request for one bad row.
            if ($publicKey === false) {
                continue;
            }

            if (openssl_verify($timestamp . $body, $decoded, $publicKey, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * SendGrid shows the key as bare base64 (DER SubjectPublicKeyInfo); OpenSSL
     * wants PEM. A key already pasted as PEM is returned unchanged.
     */
    public static function toPem(string $key): string
    {
        $key = trim($key);

        if (str_starts_with($key, '-----BEGIN')) {
            return $key;
        }

        $base64 = preg_replace('/\s+/', '', $key) ?? '';

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split($base64, 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }
}
