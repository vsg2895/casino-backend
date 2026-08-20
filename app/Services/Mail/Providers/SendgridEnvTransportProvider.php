<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Exceptions\PromotionMailerException;
use App\Models\EmailSchedule;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * SendGrid using the API key from .env (SENDGRID_API_KEY) — no stored key.
 *
 * Distinct from {@see SendgridTransportProvider}, which requires an ACTIVE key
 * row chosen in the admin. Both exist on purpose and must not be merged:
 * scheduled campaigns keep selecting a stored key, while the post-verification
 * promotion sends through the environment key with nothing to pick.
 *
 * Resolves the `sendgrid` mailer defined in config/mail.php, i.e. the same
 * transport the public verify emails use.
 */
final class SendgridEnvTransportProvider implements PromotionTransportProvider
{
    public function name(): string
    {
        return EmailSchedule::PROVIDER_SENDGRID_ENV;
    }

    public function resolve(?int $credentialId): Mailer
    {
        // Fail loudly when the environment key is absent. Without this the
        // SendGrid transport would be built with an empty credential and every
        // send would fail one message at a time, with the real cause (an
        // unconfigured .env) buried in per-recipient errors.
        if (trim((string) config('mail.mailers.sendgrid.key', '')) === '') {
            throw new PromotionMailerException(
                'SENDGRID_API_KEY is not set in the environment; cannot send with the .env SendGrid key.',
            );
        }

        return Mail::mailer('sendgrid');
    }
}
