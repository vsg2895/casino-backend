<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Exceptions\PromotionMailerException;
use App\Models\SmtpCredential;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * A stored SMTP server, sent through Laravel's built-in `smtp` transport.
 *
 * Distinct from {@see SmtpTransportProvider}, and both are needed: that one is
 * the .env mailbox every legacy schedule uses and takes no credential; this one
 * builds a mailer per row in `smtp_credentials`. Same per-credential mailer
 * naming, same purge-before-use and same failure mode as
 * {@see MailgunTransportProvider}.
 *
 * This provider is NOT registered in the promotion provider registry. Stored
 * SMTP credentials are driven only by the receiver "Run now" button, never by a
 * schedule, so nothing writes `smtp_stored` into `email_schedules.provider` and
 * registering it would advertise a choice that no scheduling screen offers.
 */
final class StoredSmtpTransportProvider implements PromotionTransportProvider
{
    public function name(): string
    {
        return 'smtp_stored';
    }

    public function resolve(?int $credentialId): Mailer
    {
        $credential = $credentialId === null
            ? null
            : SmtpCredential::query()->active()->find($credentialId);

        if ($credential === null) {
            throw new PromotionMailerException(
                "SMTP credential #{$credentialId} is missing or inactive; cannot send campaign.",
            );
        }

        return $this->mailerForCredential($credential);
    }

    /**
     * Mailer bound to a specific stored SMTP credential, active or not.
     *
     * The active-or-not part matters for the admin "Send test" action: testing a
     * credential you have just disabled — to find out WHY it is failing — has to
     * work, exactly as it does for SendGrid and Mailgun.
     *
     * @throws PromotionMailerException
     */
    public function mailerForCredential(SmtpCredential $credential): Mailer
    {
        // Completeness is the MODEL's definition, so the admin badge and this
        // guard can never disagree about what "usable" means. Unlike Mailgun,
        // from_address IS part of it: an own SMTP server supplies no sender of
        // its own.
        if (! $credential->canAuthenticate()) {
            throw new PromotionMailerException(
                "SMTP credential #{$credential->id} is missing its host, username, password or from address.",
            );
        }

        $name = 'smtp_credential_' . $credential->id;

        config()->set("mail.mailers.{$name}", [
            'transport' => 'smtp',
            'host'      => trim((string) $credential->host),
            'port'      => (int) $credential->port,
            'username'  => trim((string) $credential->username),
            'password'  => $credential->plainPassword(),
            // Laravel expects null, not the string 'none', to mean unencrypted.
            'encryption' => $credential->encryption === SmtpCredential::ENCRYPTION_NONE
                ? null
                : $credential->encryption,
            // Bounded so one unreachable server cannot hold a queue worker for
            // the whole of its timeout budget. Well inside the batch job's 240s.
            'timeout'    => 30,
        ]);

        // Config for this name may already be cached from an earlier run with
        // different credentials; without the purge the old transport is reused.
        Mail::purge($name);

        return Mail::mailer($name);
    }
}
