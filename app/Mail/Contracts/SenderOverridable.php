<?php

declare(strict_types=1);

namespace App\Mail\Contracts;

/**
 * A Mailable whose envelope "From" address can be overridden at send time while
 * keeping the template's display name. Lets a single send path point any of our
 * template-driven emails at the right sender (the .env SMTP mailbox for admin
 * mail, or a per-site domain for public SendGrid mail).
 */
interface SenderOverridable
{
    public function usingFromAddress(?string $address): static;
}
