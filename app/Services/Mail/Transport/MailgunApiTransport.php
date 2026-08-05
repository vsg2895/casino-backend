<?php

declare(strict_types=1);

namespace App\Services\Mail\Transport;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mailgun HTTP API transport.
 *
 * Hand-rolled on top of symfony/http-client rather than symfony/mailgun-mailer
 * because that bridge is not installed and adding a Composer dependency was out
 * of scope for this change. The surface we need is small and stable.
 *
 * Posts to the **messages.mime** endpoint, not the form-field one. That matters:
 * promotion mail carries RFC 8058 `List-Unsubscribe` / `List-Unsubscribe-Post`
 * headers, which is what makes Gmail and Apple Mail render a native unsubscribe
 * button. The field-based endpoint would require re-mapping every header by
 * hand and would silently drop anything not mapped; posting the rendered MIME
 * preserves the message exactly as the Mailable produced it.
 *
 * Region matters too — Mailgun's EU accounts live on a different host, and
 * sending an EU account's mail to the US endpoint fails authentication with a
 * misleading 401.
 */
final class MailgunApiTransport extends AbstractTransport
{
    public const string REGION_US = 'us';
    public const string REGION_EU = 'eu';

    /** @var array<string, string> */
    private const ENDPOINTS = [
        self::REGION_US => 'https://api.mailgun.net/v3/%s/messages.mime',
        self::REGION_EU => 'https://api.eu.mailgun.net/v3/%s/messages.mime',
    ];

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $domain,
        private readonly string $apiKey,
        private readonly string $region = self::REGION_US,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $envelope = $message->getEnvelope();

        $recipients = array_map(
            static fn (Address $address): string => $address->toString(),
            $envelope->getRecipients(),
        );

        try {
            $response = $this->client->request('POST', $this->endpoint(), [
                // Mailgun authenticates with HTTP basic auth, username literally
                // "api" and the private key as the password.
                'auth_basic' => ['api', $this->apiKey],
                'body' => [
                    // Envelope recipients, so BCC is honoured without appearing
                    // in the MIME headers.
                    'to' => $recipients,
                    'message' => $message->toString(),
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $payload = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new HttpTransportException(
                'Could not reach the Mailgun API: ' . $e->getMessage(),
                $response ?? null,
                0,
                $e,
            );
        }

        if ($statusCode !== 200) {
            throw new HttpTransportException(
                sprintf(
                    'Mailgun rejected the message (HTTP %d) for domain "%s": %s',
                    $statusCode,
                    $this->domain,
                    $this->describe($payload),
                ),
                $response,
            );
        }

        // Surface Mailgun's queue id so a delivery can be traced from our logs
        // into their dashboard.
        $id = $this->extract($payload, 'id');
        if ($id !== null) {
            $message->setMessageId($id);
        }
    }

    public function __toString(): string
    {
        return sprintf('mailgun+api://%s@%s', $this->domain, $this->region);
    }

    private function endpoint(): string
    {
        $template = self::ENDPOINTS[$this->region] ?? self::ENDPOINTS[self::REGION_US];

        return sprintf($template, urlencode($this->domain));
    }

    /** Mailgun's error body is JSON with a `message`; fall back to the raw body. */
    private function describe(string $payload): string
    {
        return $this->extract($payload, 'message') ?? mb_strimwidth(trim($payload), 0, 300, '…');
    }

    private function extract(string $payload, string $key): ?string
    {
        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($payload, true);

        return is_array($decoded) && isset($decoded[$key]) && is_scalar($decoded[$key])
            ? (string) $decoded[$key]
            : null;
    }
}
