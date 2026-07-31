<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Exceptions\PromotionMailerException;
use App\Models\EmailSchedule;
use App\Models\SendgridKey;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Resolves the mail transport for a promotion campaign from its saved provider.
 *
 *  - SMTP (default, and every pre-existing schedule): the .env SMTP mailer
 *    (config('mail.admin_mailer')) FROM the operator's authenticated mailbox —
 *    IDENTICAL to the previous behaviour, so nothing about SMTP changes.
 *
 *  - SendGrid: a runtime `sendgrid` mailer authenticated with a STORED key
 *    (decrypted from the DB), sent via the native SendGrid Web API transport
 *    registered in AppServiceProvider. The key is re-loaded and re-validated at
 *    send time, so a key disabled/deleted after scheduling throws
 *    {@see PromotionMailerException} (the job then fails gracefully).
 *
 * The From address is the same for both providers (config('mail.from.address')),
 * so switching transport never silently changes the visible sender.
 */
class PromotionMailerFactory
{
    /**
     * @throws PromotionMailerException when a SendGrid provider has no usable key.
     */
    public function resolve(string $provider, ?int $sendgridKeyId): ResolvedPromotionMailer
    {
        $fromAddress = config('mail.from.address') ?: null;

        if ($provider === EmailSchedule::PROVIDER_SENDGRID) {
            return new ResolvedPromotionMailer(
                $this->sendgridMailer($sendgridKeyId),
                $fromAddress,
            );
        }

        // SMTP (default): unchanged .env transport.
        return new ResolvedPromotionMailer(
            Mail::mailer(config('mail.admin_mailer')),
            $fromAddress,
        );
    }

    /**
     * Build a SendGrid mailer bound to a SPECIFIC stored key — including an
     * INACTIVE one. Campaign sends never reach this directly (they go through
     * {@see sendgridMailer()}, which enforces the active check); it is public so
     * the admin "Send test" action can verify a key before enabling it, or
     * diagnose one that was disabled.
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

    /**
     * The campaign path: resolve the stored key by id, requiring it to still be
     * ACTIVE at send time.
     *
     * @throws PromotionMailerException
     */
    private function sendgridMailer(?int $sendgridKeyId): Mailer
    {
        $key = $sendgridKeyId === null
            ? null
            : SendgridKey::query()->active()->find($sendgridKeyId);

        if ($key === null) {
            throw new PromotionMailerException(
                "SendGrid key #{$sendgridKeyId} is missing or inactive; cannot send promotion campaign.",
            );
        }

        return $this->mailerForKey($key);
    }
}
