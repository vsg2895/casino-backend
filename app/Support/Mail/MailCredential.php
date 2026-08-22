<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\EmailSchedule;
use App\Models\MailgunKey;
use App\Models\SendgridKey;
use Throwable;

/**
 * A SAFE description of the credential a send actually used.
 *
 * Exists so "which key did that go out with?" is answerable from the log
 * without ever putting key material in it. Written once and used by both the
 * send job and the diagnostic command, so the two can never disagree about
 * what they are reporting.
 *
 * WHAT IT DELIBERATELY DOES NOT INCLUDE: the key. Not the tail, not the middle.
 * {@see SendgridKey::maskedKey()} shows head+tail for an admin staring at a
 * screen they had to log into; a log file is copied, shipped to aggregators and
 * pasted into tickets, so it gets less. The prefix alone identifies the
 * PROVIDER format ("SG."), and the fingerprint — a SHA-256 truncation — proves
 * IDENTITY: same fingerprint means same key, and it cannot be reversed.
 *
 * To confirm production is using the .env key, compare the logged fingerprint
 * with:  printf %s "$SENDGRID_API_KEY" | shasum -a 256 | cut -c1-12
 */
final class MailCredential
{
    /** Hex characters of the SHA-256 kept. Enough to be unique, useless to an attacker. */
    private const int FINGERPRINT_LENGTH = 12;

    /**
     * @return array{source: string, key_prefix: string, key_fingerprint: string}
     */
    public static function describe(string $provider, ?int $credentialId): array
    {
        return match ($provider) {
            EmailSchedule::PROVIDER_SENDGRID_ENV => self::fromEnvSendgrid(),
            EmailSchedule::PROVIDER_SENDGRID     => self::fromStoredSendgrid($credentialId),
            EmailSchedule::PROVIDER_MAILGUN      => self::fromStoredMailgun($credentialId),
            default                              => [
                'source'          => '.env SMTP mailer (' . (string) config('mail.admin_mailer') . ')',
                'key_prefix'      => '',
                'key_fingerprint' => '',
            ],
        };
    }

    /** @return array{source: string, key_prefix: string, key_fingerprint: string} */
    private static function fromEnvSendgrid(): array
    {
        $key = (string) config('mail.mailers.sendgrid.key', '');

        return [
            'source'          => '.env SENDGRID_API_KEY',
            'key_prefix'      => self::prefix($key),
            'key_fingerprint' => self::fingerprint($key),
        ];
    }

    /** @return array{source: string, key_prefix: string, key_fingerprint: string} */
    private static function fromStoredSendgrid(?int $id): array
    {
        try {
            $row = $id === null ? null : SendgridKey::find($id);
            // Reading the encrypted cast can throw on a key rotation mismatch;
            // a description must never break the send it is describing.
            $key = $row === null ? '' : (string) $row->api_key;
        } catch (Throwable) {
            $row = null;
            $key = '';
        }

        return [
            'source'          => $row === null
                ? "stored SendGrid key #{$id} (missing)"
                : "stored SendGrid key #{$row->id} ({$row->name})",
            'key_prefix'      => self::prefix($key),
            'key_fingerprint' => self::fingerprint($key),
        ];
    }

    /** @return array{source: string, key_prefix: string, key_fingerprint: string} */
    private static function fromStoredMailgun(?int $id): array
    {
        try {
            $row = $id === null ? null : MailgunKey::find($id);
            $key = $row === null ? '' : (string) $row->api_key;
        } catch (Throwable) {
            $row = null;
            $key = '';
        }

        return [
            'source'          => $row === null
                ? "stored Mailgun key #{$id} (missing)"
                : "stored Mailgun key #{$row->id} ({$row->name})",
            'key_prefix'      => self::prefix($key),
            'key_fingerprint' => self::fingerprint($key),
        ];
    }

    /** Provider format marker only — "SG." and nothing that identifies the key. */
    private static function prefix(string $key): string
    {
        return $key === '' ? '(empty)' : substr($key, 0, 3) . '…';
    }

    /** Non-reversible identity. Same key ⇒ same value; the key cannot be derived. */
    private static function fingerprint(string $key): string
    {
        return $key === '' ? '(none)' : substr(hash('sha256', $key), 0, self::FINGERPRINT_LENGTH);
    }
}
