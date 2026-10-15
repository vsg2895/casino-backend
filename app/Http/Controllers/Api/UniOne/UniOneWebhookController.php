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
 * A payload that carries events and fails the hash gets 401 and is logged.
 *
 * Returns 200 for anything it has accepted OR already seen. UniOne re-delivers
 * until it gets a 200, so answering anything else to a duplicate would make it
 * retry forever.
 *
 * ── The verification probe, and why it is not a hole ────────────────────────
 *
 * `webhook/set.json` CHECKS THE URL before it saves it: UniOne calls the
 * address and refuses to register anything that does not answer 200. That probe
 * carries no events — there are none yet — so it cannot be signed the way a
 * real batch is, and answering it 401 made registration impossible. The button
 * failed with "Could not register the webhook" while the endpoint itself was
 * working perfectly for real events.
 *
 * So a request with NO events is answered 200 and nothing else happens: nothing
 * is read from it, nothing is written, and `last_webhook_event_at` is NOT
 * stamped — an unverified probe is not proof that events are arriving. The hash
 * still gates every event that could mark an address bounced or complained,
 * which is the only thing it was ever protecting.
 *
 * GET is accepted for the same reason and answers the same way; some providers
 * probe with it, and a 405 would be the same dead end as the 401.
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
        $payload = json_decode($raw, true);
        $events = $this->eventsIn($payload);

        /*
         * A VERIFICATION PROBE: no events to apply, so there is nothing to
         * authenticate and nothing to do but say yes. This is what lets
         * `webhook/set.json` accept the URL at all — see the class note.
         */
        if ($events === []) {
            return response()->json(['status' => 'success', 'ingested' => 0, 'duplicates' => 0]);
        }

        // Gate 2: the documented MD5-with-api-key check, over the RAW bytes.
        if (! UniOneWebhookVerifier::verify($raw, (string) $key->api_key)) {
            Log::warning('UniOne webhook rejected: signature mismatch', [
                'key_id' => $key->id,
                'ip'     => $request->ip(),
                'bytes'  => strlen($raw),
            ]);

            return response()->json(['status' => 'error'], Response::HTTP_UNAUTHORIZED);
        }

        $ingested = 0;
        $duplicates = 0;

        /*
         * Proof of life, stamped on the key before anything is parsed.
         *
         * A registration can succeed and still deliver nothing — a wrong URL, a
         * firewall, a provider-side mistake — and until now there was no way to
         * tell that apart from "nothing has bounced". This column is what the
         * admin reads to say when events last arrived.
         */
        $key->forceFill(['last_webhook_event_at' => now()])->save();

        foreach ($events as $event) {
            $result = $this->ingest($key, $event);
            $result === 'duplicate' ? $duplicates++ : $ingested++;
        }

        return response()->json(['status' => 'success', 'ingested' => $ingested, 'duplicates' => $duplicates]);
    }

    /**
     * Every event in the payload, flattened out of its per-user blocks.
     *
     * Returns an EMPTY array for anything that is not a batch of events — a
     * probe, an empty body, malformed JSON — which is exactly the case the
     * caller treats as "nothing to authenticate, nothing to do".
     *
     * @return list<array<string, mixed>>
     */
    private function eventsIn(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $events = [];

        foreach ((array) ($payload['events_by_user'] ?? []) as $userBlock) {
            foreach ((array) (($userBlock['events'] ?? null) ?: []) as $event) {
                if (is_array($event)) {
                    $events[] = $event;
                }
            }
        }

        return $events;
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
