<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\UniOne;

use App\Http\Controllers\Controller;
use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneReceiver;
use App\Models\UniOne\UniOneWebhookEvent;
use App\Services\UniOne\UniOneOutcomeService;
use App\Support\UniOne\UniOneWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ingests UniOne delivery events.
 *
 * Two gates, in order:
 *   1. the URL path token — an unauthenticated prober never reaches the body
 *   2. the MD5 auth hash  — the cryptographic check UniOne actually documents
 *
 * Unverified payloads get 401 and are logged, per the brief.
 *
 * Returns 200 for anything it has accepted OR already seen. UniOne re-delivers
 * until it gets a 200, so answering anything else to a duplicate would make it
 * retry forever.
 */
class UniOneWebhookController extends Controller
{
    public function __construct(private readonly UniOneOutcomeService $outcomes) {}

    public function __invoke(Request $request, string $token): JsonResponse
    {
        // Gate 1: the token identifies WHICH key's webhook this is. Compared
        // against the decrypted column, so a wrong token finds no key at all.
        $key = $this->keyForToken($token);

        if ($key === null) {
            Log::warning('UniOne webhook rejected: unknown token', ['ip' => $request->ip()]);

            return response()->json(['status' => 'error'], Response::HTTP_UNAUTHORIZED);
        }

        $raw = $request->getContent();

        // Gate 2: the documented MD5-with-api-key check, over the RAW bytes.
        if (! UniOneWebhookVerifier::verify($raw, (string) $key->api_key)) {
            Log::warning('UniOne webhook rejected: signature mismatch', [
                'key_id' => $key->id,
                'ip'     => $request->ip(),
                'bytes'  => strlen($raw),
            ]);

            return response()->json(['status' => 'error'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($raw, true);
        $ingested = 0;
        $duplicates = 0;

        foreach ((array) ($payload['events_by_user'] ?? []) as $userBlock) {
            foreach ((array) ($userBlock['events'] ?? []) as $event) {
                $result = $this->ingest($key, (array) $event);
                $result === 'duplicate' ? $duplicates++ : $ingested++;
            }
        }

        return response()->json(['status' => 'success', 'ingested' => $ingested, 'duplicates' => $duplicates]);
    }

    /**
     * Store one event, once.
     *
     * Idempotency is the UNIQUE INDEX on `event_hash`, not a read-then-write:
     * two concurrent re-deliveries would both read "absent" and both insert.
     * A duplicate-key violation is the expected, correct outcome here — caught
     * and reported as a duplicate rather than an error.
     *
     * @param  array<string, mixed>  $event
     */
    private function ingest(UniOneApiKey $key, array $event): string
    {
        $name = (string) ($event['event_name'] ?? '');
        $data = (array) ($event['event_data'] ?? []);

        $email = isset($data['email']) ? (string) $data['email'] : null;
        $status = isset($data['status']) ? (string) $data['status'] : null;
        $jobId = isset($data['job_id']) ? (string) $data['job_id'] : null;
        $eventTime = isset($data['event_time']) ? (string) $data['event_time'] : null;

        $hash = UniOneWebhookEvent::hashFor($name, $jobId, $email, $status, $eventTime);

        $delivery = (array) ($data['delivery_info'] ?? []);

        try {
            $record = UniOneWebhookEvent::query()->create([
                'unione_api_key_id'    => $key->id,
                'event_name'           => $name,
                'job_id'               => $jobId,
                'email'                => $email,
                'status'               => $status,
                'event_time'           => $eventTime,
                'delivery_status'      => isset($delivery['delivery_status']) ? (string) $delivery['delivery_status'] : null,
                'destination_response' => isset($delivery['destination_response']) ? (string) $delivery['destination_response'] : null,
                'url'                  => isset($data['url']) ? mb_substr((string) $data['url'], 0, 500) : null,
                'payload'              => $event,
                'event_hash'           => $hash,
                'applied'              => false,
                'created_at'           => now(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // 23000 = integrity constraint violation, i.e. the dedup key fired.
            if ($e->getCode() === '23000') {
                return 'duplicate';
            }

            throw $e;
        }

        // Only email_status events describe an address.
        if ($name === 'transactional_email_status' && $email !== null && $status !== null) {
            $receiver = UniOneReceiver::query()->where('email', $email)->first();

            if ($receiver !== null) {
                // The SAME mapping the synchronous failed_emails path uses.
                $changed = $this->outcomes->applyEventStatus($receiver, $status);
                $record->forceFill(['applied' => $changed])->save();
            }
        }

        return 'ingested';
    }

    /**
     * Resolve the key whose webhook token this is.
     *
     * `webhook_secret` is an encrypted cast, so it cannot be matched in SQL —
     * the comparison happens in PHP over the active keys, which is a handful of
     * rows. hash_equals keeps it timing-safe.
     */
    private function keyForToken(string $token): ?UniOneApiKey
    {
        if (trim($token) === '') {
            return null;
        }

        return UniOneApiKey::query()->active()->get()
            ->first(static fn (UniOneApiKey $k): bool => hash_equals((string) $k->webhook_secret, $token));
    }
}
