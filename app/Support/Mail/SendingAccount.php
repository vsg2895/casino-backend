<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Contracts\ReceiverCampaignCredential;
use App\Models\EmailSchedule;
use App\Models\SmtpCredential;

/**
 * Names the SENDING ACCOUNT a bulk message goes out through — the unit the
 * send-rate window is kept per ({@see \App\Services\Mail\WarmupRateLimiter::forAccount()}).
 *
 * An account is what a receiving host and the provider see as one sender: the
 * .env SMTP mailbox, one SendGrid key, one Mailgun credential, one stored SMTP
 * server. The same key used by a schedule and by a receiver campaign is ONE
 * account and shares one quota; that is why both paths name it here, the same
 * way, rather than each inventing a string.
 */
final class SendingAccount
{
    /** A schedule's transport: its provider plus the stored credential, if any. */
    public static function forProvider(string $provider, ?int $credentialId): string
    {
        return match ($provider) {
            EmailSchedule::PROVIDER_SENDGRID     => 'sendgrid:' . (int) $credentialId,
            EmailSchedule::PROVIDER_MAILGUN      => 'mailgun:' . (int) $credentialId,
            EmailSchedule::PROVIDER_SENDGRID_ENV => 'sendgrid-env',
            // SMTP from .env sends through the admin mailer — a named mailer, so
            // it matches warmup's account whenever the two are the same mailbox.
            default                              => 'mailer:' . (string) config('mail.admin_mailer'),
        };
    }

    /** A receiver campaign's credential. */
    public static function forReceiverCredential(ReceiverCampaignCredential $credential): string
    {
        $id = (int) $credential->getKey();

        return $credential instanceof SmtpCredential
            ? 'smtp-credential:' . $id
            : $credential->campaignChannel() . ':' . $id;
    }
}
