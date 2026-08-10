<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\TwilioConfig;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Sends one SMS through Twilio's REST API.
 *
 * Hand-rolled against the documented HTTP endpoint rather than through
 * twilio/sdk, for the same reason {@see \App\Services\Mail\Transport\MailgunApiTransport}
 * is: the surface used here is a single form POST, and adding a Composer
 * dependency is not something this change can install. If the SDK is added later,
 * this class is the only place that has to change.
 *
 * Credentials are per-configuration and arrive as a {@see TwilioConfig}; nothing
 * is read from .env. Only the row id travels through the queue — the token is
 * decrypted here, at send time, so a serialised job payload never carries a
 * secret.
 *
 * Never throws for a rejected recipient. Twilio answers a bad number with a 400
 * and a numeric code, which is per-recipient information the caller records and
 * moves on from; see {@see SmsSendResult}. It DOES throw for a credential that
 * cannot send at all, because that is a condition of the entire run and failing
 * fast beats emitting one identical error per recipient.
 */
class TwilioSmsClient
{
    /** Twilio's API version prefix — part of the documented URL, not a setting. */
    private const string API_BASE = 'https://api.twilio.com/2010-04-01';

    /** Fallback seconds to wait on one API call; see config/sms.php. */
    private const int REQUEST_TIMEOUT = 15;

    /**
     * @throws RuntimeException when the credential itself cannot send.
     */
    public function send(TwilioConfig $config, string $to, string $body): SmsSendResult
    {
        $sender = $config->senderIdentity();

        if ($sender === null) {
            // A run-wide fault: every recipient would fail identically.
            throw new RuntimeException(
                "Twilio configuration \"{$config->name}\" has neither a from number nor a Messaging Service SID.",
            );
        }

        $accountSid = trim((string) $config->account_sid);
        $authToken = (string) $config->auth_token;

        if ($accountSid === '' || $authToken === '') {
            throw new RuntimeException(
                "Twilio configuration \"{$config->name}\" is missing its Account SID or Auth Token.",
            );
        }

        [$senderParam, $senderValue] = $sender;

        try {
            $response = Http::asForm()
                ->withBasicAuth($accountSid, $authToken)
                ->timeout((int) config('sms.request_timeout', self::REQUEST_TIMEOUT))
                ->post(self::API_BASE . "/Accounts/{$accountSid}/Messages.json", [
                    'To'          => $to,
                    'Body'        => $body,
                    $senderParam  => $senderValue,
                ]);
        } catch (Throwable $e) {
            // Connection-level: no HTTP answer at all. Per-recipient rather than
            // fatal, because a single timeout should not abandon the batch — the
            // recorded failure tells the admin exactly which numbers to retry.
            return SmsSendResult::failed(null, 'Could not reach Twilio: ' . $e->getMessage());
        }

        if ($response->successful()) {
            return $this->interpretAccepted($response);
        }

        // A 401 means the stored token is wrong or revoked — true for every
        // recipient, so stop the run instead of writing one failure per number.
        if ($response->status() === 401) {
            throw new RuntimeException(
                "Twilio rejected the credentials for \"{$config->name}\" (401 Unauthorized). "
                . 'Check the Account SID and Auth Token.',
            );
        }

        return SmsSendResult::failed(
            $this->intOrNull($response->json('code')),
            $this->errorMessageFrom($response),
        );
    }

    /**
     * A 2xx from Twilio means "queued for delivery", not "delivered" — and it can
     * still carry an error code when the message was accepted but immediately
     * failed. Read the body rather than trusting the status line.
     */
    private function interpretAccepted(Response $response): SmsSendResult
    {
        $errorCode = $this->intOrNull($response->json('error_code'));

        if ($errorCode !== null) {
            $message = $response->json('error_message');

            return SmsSendResult::failed(
                $errorCode,
                is_string($message) && $message !== '' ? $message : "Twilio error {$errorCode}.",
            );
        }

        $sid = $response->json('sid');

        return SmsSendResult::sent(is_string($sid) && $sid !== '' ? $sid : null);
    }

    /** Twilio's own message when it sent one, otherwise something diagnosable. */
    private function errorMessageFrom(Response $response): string
    {
        $message = $response->json('message');

        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        // Truncated: an HTML error page from an intermediary proxy would
        // otherwise fill the history row.
        $body = trim((string) $response->body());

        return $body === ''
            ? "Twilio returned HTTP {$response->status()} with an empty body."
            : "Twilio returned HTTP {$response->status()}: " . mb_strimwidth($body, 0, 300, '…');
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
