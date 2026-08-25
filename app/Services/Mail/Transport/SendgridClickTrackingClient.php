<?php

declare(strict_types=1);

namespace App\Services\Mail\Transport;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Turns SendGrid click tracking OFF for a single message, from the message itself.
 *
 * WHY A DECORATOR AND NOT A TRANSPORT. `tracking_settings` has to go in the
 * /v3/mail/send request body, and Symfony builds that body in
 * {@see \Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport::getPayload()},
 * which is PRIVATE — there is no subclass hook. The alternatives were rewriting
 * the whole payload builder (duplicating SendGrid's mapping, and re-breaking on
 * every upgrade) or reflection. Wrapping the HTTP client instead touches nothing
 * but the finished JSON body, so SendGrid's own mapping stays authoritative.
 *
 * WHY PER MESSAGE AND NOT PER MAILER. The `sendgrid` mailer carries several
 * streams. Only the message that opts in is changed: a Mailable adds
 * {@see DISABLE_HEADER}, this strips it back out and swaps it for the tracking
 * setting. Every other email sent through the same mailer is passed through
 * byte-for-byte, so their click tracking keeps following the account settings.
 *
 * Deliberately does NOT touch `subscription_tracking`. Enabling that would let
 * SendGrid replace our own List-Unsubscribe header with its own, which would
 * route opt-outs into SendGrid's suppression list instead of the `unsubscribes`
 * table the whole platform gates sends on.
 */
final class SendgridClickTrackingClient implements HttpClientInterface
{
    use DecoratorTrait;

    /**
     * Marker a Mailable sets to opt out of click tracking.
     *
     * Never reaches SendGrid — it is removed here and replaced by the real
     * setting. On a non-SendGrid transport (the admin "send test" goes over SMTP)
     * it is simply an inert X- header.
     */
    public const string DISABLE_HEADER = 'X-Sendgrid-Click-Tracking';

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $payload = $options['json'] ?? null;

        if (is_array($payload) && $this->stripMarker($payload)) {
            // enable_text covers the plain-text part; without it SendGrid still
            // rewrites the pasted fallback link, which is the one recipients read
            // most carefully in a verification email.
            $payload['tracking_settings']['click_tracking'] = [
                'enable'      => false,
                'enable_text' => false,
            ];

            $options['json'] = $payload;
        }

        return $this->client->request($method, $url, $options);
    }

    /**
     * Remove the marker header from the payload, reporting whether it was there.
     *
     * Case-insensitive: the key comes from the MIME header's own casing, which is
     * not ours to assume.
     *
     * @param  array<string, mixed>  $payload
     */
    private function stripMarker(array &$payload): bool
    {
        $headers = $payload['headers'] ?? null;

        if (! is_array($headers)) {
            return false;
        }

        $found = false;

        foreach (array_keys($headers) as $name) {
            if (is_string($name) && strcasecmp($name, self::DISABLE_HEADER) === 0) {
                unset($headers[$name]);
                $found = true;
            }
        }

        if (! $found) {
            return false;
        }

        // SendGrid rejects an empty `headers` object, so drop the key entirely
        // when the marker was the only one.
        if ($headers === []) {
            unset($payload['headers']);
        } else {
            $payload['headers'] = $headers;
        }

        return true;
    }
}
