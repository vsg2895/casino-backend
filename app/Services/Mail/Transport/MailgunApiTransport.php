<?php

declare(strict_types=1);

namespace App\Services\Mail\Transport;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Mailgun HTTP API transport.
 *
 * Hand-rolled on top of symfony/http-client rather than symfony/mailgun-mailer
 * because that bridge is not installed and adding a Composer dependency was out
 * of scope for this change. The surface we need is small and stable.
 *
 * Posts to the **messages.mime** endpoint, not the form-field one. That endpoint
 * accepts multipart/form-data ONLY — see doSend() — and that matters:
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

        // The messages.mime endpoint accepts ONLY multipart/form-data. Passing a
        // plain array as `body` makes symfony/http-client encode it as
        // application/x-www-form-urlencoded, which Mailgun rejects outright:
        //
        //   400 "Invalid request content type. Expecting 'multipart/form-data'
        //        but got 'application/x-www-form-urlencoded'"
        //
        // So the body is built as a FormDataPart and streamed, with its prepared
        // Content-Type (including the generated boundary) passed as a header.
        //
        // Each recipient is its own integer-keyed single-element array, which is
        // how FormDataPart emits REPEATED `to` fields. A string key with an array
        // value would produce `to[0]`, `to[1]` instead, which Mailgun does not
        // accept — and comma-joining is unsafe here because a display name may
        // itself contain a comma ("Doe, John" <j@x.com>).
        $fields = [];
        foreach ($recipients as $recipient) {
            $fields[] = ['to' => $recipient];
        }
        // Filename `message.mime` and no explicit content type, matching
        // symfony/mailgun-mailer's own transport.
        $fields['message'] = new DataPart($message->toString(), 'message.mime');

        $form = new FormDataPart($fields);

        try {
            $response = $this->client->request('POST', $this->endpoint(), [
                // Mailgun authenticates with HTTP basic auth, username literally
                // "api" and the private key as the password.
                'auth_basic' => ['api', $this->apiKey],
                'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(),
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
