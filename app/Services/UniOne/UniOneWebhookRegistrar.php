<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Models\UniOne\UniOneApiKey;
use App\Support\UniOne\UniOneResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells UniOne where to post delivery events.
 *
 * ── Why this class had to exist ─────────────────────────────────────────────
 *
 * Everything on the receiving side was already built: the endpoint, the token
 * gate, the MD5 verification, de-duplication by event hash, and the status
 * mapping that turns `hard_bounced` into a bounced receiver and `spam` into a
 * complaint. The only missing step was the one that starts it:
 * `UniOneClient::setWebhook()` had no caller, and the admin merely displayed the
 * URL for somebody to paste into UniOne by hand.
 *
 * The visible symptom is exactly what was reported — failures counted in the
 * UniOne dashboard, a receiver list with no bounced or complained addresses.
 *
 * ── What it asks for, and why that list ─────────────────────────────────────
 *
 * Only `email_status` events, and only the statuses the platform acts on:
 *
 *   hard_bounced   → receiver bounced, excluded from future sends
 *   spam           → receiver complained, excluded
 *   unsubscribed   → receiver unsubscribed, excluded
 *   soft_bounced   → a retry window, status untouched (it describes the moment)
 *   delivered/opened/clicked/sent → recorded as `last_status`, eligibility
 *                                   unchanged
 *
 * Asking for nothing else keeps the endpoint's volume to what it can act on.
 *
 * ── It does not touch sending ───────────────────────────────────────────────
 *
 * Registration is a separate API call on a separate endpoint. Nothing here is
 * read by {@see UniOneSendService}, and a failed registration leaves sending
 * exactly as it was — the list simply keeps missing bounce signals, which is
 * the state this fixes rather than a new risk it introduces.
 */
class UniOneWebhookRegistrar
{
    /**
     * The `email_status` events to subscribe to.
     *
     * @var list<string>
     */
    public const array EVENTS = [
        'sent',
        'delivered',
        'opened',
        'clicked',
        'unsubscribed',
        'soft_bounced',
        'hard_bounced',
        'spam',
    ];

    /**
     * Register (or re-register) this key's webhook.
     *
     * Returns what happened, for the admin to display. The provider's answer is
     * stored VERBATIM — including an error — because the one thing worse than a
     * webhook that is not registered is a webhook that reports success and
     * delivers nothing.
     *
     * @return array{ok: bool, message: string}
     */
    public function register(UniOneApiKey $key, string $url): array
    {
        $payload = [
            'url'    => $url,
            'events' => ['email_status' => self::EVENTS],
            'status' => 'active',
            /*
             * 5, because UniOne REFUSES anything lower:
             *
             *   Error in 'max_parallel' field. This value should be between 5
             *   and 100.
             *
             * It was 1, meaning "one delivery at a time", and that number is
             * what made every registration fail — the URL was never the
             * problem. 5 is their floor, and the lowest concurrency they will
             * accept is what this endpoint wants: it writes a row and may
             * update a receiver per event.
             *
             * Concurrency is safe here regardless. Ingestion is idempotent on a
             * UNIQUE `event_hash`, so two deliveries of one event race to the
             * same row and the loser is reported as a duplicate, not an error.
             */
            'max_parallel' => 5,
            // Batched rather than one request per event — UniOne retries until
            // it gets a 200, and a batch means one retry instead of many.
            'single_event' => false,
        ];

        try {
            $response = (new UniOneClient($key))->setWebhook($payload);
        } catch (Throwable $e) {
            return $this->record($key, false, 'Could not reach UniOne: ' . $e->getMessage());
        }

        if (! $response->ok) {
            // UniOne answers failures with a message; show it rather than a
            // generic "registration failed", which tells an operator nothing.
            return $this->record($key, false, $this->describe($response));
        }

        return $this->record($key, true, 'Registered. UniOne will post delivery events to this URL.');
    }

    /**
     * Store the outcome on the key.
     *
     * `webhook_registered_at` moves only on success, so it always answers "when
     * did this last actually work" rather than "when was it last attempted".
     *
     * @return array{ok: bool, message: string}
     */
    private function record(UniOneApiKey $key, bool $ok, string $message): array
    {
        $updates = ['webhook_status' => mb_substr($message, 0, 255)];

        if ($ok) {
            $updates['webhook_registered_at'] = now();
        }

        $key->forceFill($updates)->save();

        if (! $ok) {
            Log::warning('UniOne webhook registration failed', [
                'key_id'  => $key->id,
                'message' => $message,
            ]);
        }

        return ['ok' => $ok, 'message' => $message];
    }

    /**
     * A readable line from a failed response.
     *
     * UniOne's own message when there is one — "api_key is invalid",
     * "url is unreachable" — because that is what tells an operator what to fix.
     * The HTTP status is the fallback, never the headline.
     */
    private function describe(UniOneResponse $response): string
    {
        $message = trim((string) $response->message);

        if ($message !== '') {
            return $message;
        }

        return 'UniOne refused the registration (HTTP ' . $response->httpStatus . ').';
    }
}
