<?php

declare(strict_types=1);

namespace App\Services\Mail\Providers;

use App\Exceptions\PromotionMailerException;
use Illuminate\Contracts\Mail\Mailer;

/**
 * One email transport a promotion campaign can be sent through.
 *
 * Adding a provider is: implement this interface, register it in
 * {@see \App\Services\Mail\PromotionMailerFactory}, add its credential table and
 * its id column on email_schedules. Nothing in the scheduling, batching,
 * history or retry logic has to change — those layers only ever see a Mailer.
 */
interface PromotionTransportProvider
{
    /** The value stored in email_schedules.provider. */
    public function name(): string;

    /**
     * Build the mailer for a campaign.
     *
     * @param  int|null  $credentialId  Row id in this provider's credential
     *                                  table, or null for providers configured
     *                                  entirely from the environment (SMTP).
     *
     * @throws PromotionMailerException when the credential is missing, inactive
     *         or unusable. Callers treat this as "skip this batch gracefully",
     *         never as a crash.
     */
    public function resolve(?int $credentialId): Mailer;
}
