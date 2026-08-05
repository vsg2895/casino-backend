<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Models\EmailSchedule;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * The .env SMTP transport — the default, and every pre-existing schedule.
 *
 * Behaviour is IDENTICAL to what PromotionMailerFactory did inline before the
 * provider abstraction was introduced: the mailer named by
 * config('mail.admin_mailer'), no credential lookup, no failure mode.
 */
final class SmtpTransportProvider implements PromotionTransportProvider
{
    public function name(): string
    {
        return EmailSchedule::PROVIDER_SMTP;
    }

    public function resolve(?int $credentialId): Mailer
    {
        return Mail::mailer(config('mail.admin_mailer'));
    }
}
