<?php

declare(strict_types=1);

namespace App\Support\UniOne;

/**
 * Verifies that a webhook really came from UniOne.
 *
 * ── The mechanism, quoted from the OpenAPI spec ─────────────────────────────
 *
 *   auth: "MD5-hash of the message body, in which the value 'auth' is replaced
 *          by api key of the user/project; with this field the recipient of the
 *          notification can both authenticate and verify the notification
 *          integrity"
 *
 * So: take the RAW body, swap the value of `auth` for the API key, MD5 the
 * result, compare with the `auth` that arrived.
 *
 * ── Why this is not `webhook_secret` ────────────────────────────────────────
 *
 * The brief asked for verification with a secret we generate. UniOne offers no
 * such thing — it signs with the API key. A self-generated secret would have no
 * role in this computation, so `webhook_secret` is used instead as a URL path
 * token: an unauthenticated prober cannot even reach the endpoint. Two
 * independent gates, neither pretending to be the other.
 *
 * ── Why the RAW body, and not the re-encoded array ──────────────────────────
 *
 * MD5 is over the exact bytes UniOne hashed. Decoding to an array and
 * re-encoding would reorder keys, change escaping and alter whitespace, and the
 * hash would never match. The replacement is therefore a string operation on the
 * raw payload.
 */
final class UniOneWebhookVerifier
{
    /**
     * @param  string  $rawBody  the request body exactly as received
     * @param  string  $apiKey   the decrypted UniOne key
     */
    public static function verify(string $rawBody, string $apiKey): bool
    {
        $decoded = json_decode($rawBody, true);

        if (! is_array($decoded) || ! isset($decoded['auth']) || ! is_string($decoded['auth'])) {
            return false;
        }

        $received = $decoded['auth'];

        if ($received === '' || $apiKey === '') {
            return false;
        }

        $substituted = self::substituteAuth($rawBody, $received, $apiKey);

        if ($substituted === null) {
            return false;
        }

        // hash_equals, not ===: a timing-safe comparison, because this value
        // gates whether a payload is trusted to mutate the receiver list.
        return hash_equals(md5($substituted), $received);
    }

    /**
     * Replace the auth VALUE in the raw body with the API key.
     *
     * Replaces the first occurrence of the exact quoted token only. A blind
     * str_replace of the hash would also rewrite it if the same string happened
     * to appear elsewhere in the payload, silently breaking the hash.
     */
    private static function substituteAuth(string $rawBody, string $authValue, string $apiKey): ?string
    {
        $needle = '"' . $authValue . '"';
        $position = strpos($rawBody, $needle);

        if ($position === false) {
            return null;
        }

        return substr_replace($rawBody, '"' . $apiKey . '"', $position, strlen($needle));
    }
}
