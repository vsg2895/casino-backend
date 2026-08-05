<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Exceptions\PromotionMailerException;
use App\Models\EmailSchedule;
use App\Models\SendgridKey;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * A stored SendGrid key, sent through the native SendGrid Web API transport
 * registered in AppServiceProvider.
 *
 * Lifted verbatim out of PromotionMailerFactory when the provider abstraction
 * was introduced — same active-key requirement, same per-key mailer naming,
 * same purge-before-use, same exception message. Nothing about SendGrid
 * behaviour changed.
 */
final class SendgridTransportProvider implements PromotionTransportProvider
{
    public function name(): string
    {
        return EmailSchedule::PROVIDER_SENDGRID;
    }

    public function resolve(?int $credentialId): Mailer
    {
        $key = $credentialId === null
            ? null
            : SendgridKey::query()->active()->find($credentialId);

        if ($key === null) {
            throw new PromotionMailerException(
                "SendGrid key #{$credentialId} is missing or inactive; cannot send promotion campaign.",
            );
        }

        return $this->mailerForKey($key);
    }

    /**
     * Build a mailer bound to a SPECIFIC stored key — including an INACTIVE one.
     *
     * Campaign sends never reach this directly (they go through resolve(), which
     * enforces the active check); it is public so the admin "Send test" action
     * can verify a key before enabling it, or diagnose one that was disabled.
     *
     * Uses a per-key mailer name so long-lived queue workers never reuse another
     * key's cached transport, and purges it first so a rotated key value is
     * always picked up.
     *
     * @throws PromotionMailerException when the stored key value is empty.
     */
    public function mailerForKey(SendgridKey $key): Mailer
    {
        $plainKey = (string) $key->api_key; // decrypted via the model cast
        if ($plainKey === '') {
            throw new PromotionMailerException(
                "SendGrid key #{$key->id} has an empty value; cannot authenticate.",
            );
        }

        $name = 'sendgrid_key_' . $key->id;
        config()->set("mail.mailers.{$name}", ['transport' => 'sendgrid', 'key' => $plainKey]);
        Mail::purge($name);

        return Mail::mailer($name);
    }
}
