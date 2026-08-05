<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Exceptions\PromotionMailerException;
use App\Models\EmailSchedule;
use App\Models\MailgunKey;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * A stored Mailgun credential, sent through the `mailgun` transport registered
 * in AppServiceProvider.
 *
 * Deliberately mirrors {@see SendgridTransportProvider} — same active-key
 * requirement, same per-credential mailer naming, same purge-before-use, same
 * failure mode. A Mailgun credential is a (domain, key, region) triple rather
 * than a bare key, which is the only provider-specific difference.
 */
final class MailgunTransportProvider implements PromotionTransportProvider
{
    public function name(): string
    {
        return EmailSchedule::PROVIDER_MAILGUN;
    }

    public function resolve(?int $credentialId): Mailer
    {
        $key = $credentialId === null
            ? null
            : MailgunKey::query()->active()->find($credentialId);

        if ($key === null) {
            throw new PromotionMailerException(
                "Mailgun key #{$credentialId} is missing or inactive; cannot send promotion campaign.",
            );
        }

        return $this->mailerForKey($key);
    }

    /**
     * Build a mailer bound to a SPECIFIC stored credential — including an
     * INACTIVE one, so the admin can verify it before enabling it.
     *
     * The per-credential mailer name keeps a long-lived queue worker from
     * reusing another domain's cached transport, and the purge means a rotated
     * key or a changed domain is picked up immediately.
     *
     * @throws PromotionMailerException when the credential is incomplete.
     */
    public function mailerForKey(MailgunKey $key): Mailer
    {
        $plainKey = (string) $key->api_key; // decrypted via the model cast
        $domain = trim((string) $key->domain);

        if ($plainKey === '' || $domain === '') {
            throw new PromotionMailerException(
                "Mailgun key #{$key->id} is missing its domain or API key; cannot authenticate.",
            );
        }

        $name = 'mailgun_key_' . $key->id;
        config()->set("mail.mailers.{$name}", [
            'transport' => 'mailgun',
            'domain'    => $domain,
            'key'       => $plainKey,
            'region'    => $key->region,
        ]);
        Mail::purge($name);

        return Mail::mailer($name);
    }
}
