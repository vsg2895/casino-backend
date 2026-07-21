<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

/**
 * Implements {@see \App\Mail\Contracts\SenderOverridable}: holds an optional
 * From-address override that {@see envelope()} applies in place of the template's
 * own from_email. The display name always stays the template's from_name.
 */
trait HasSenderOverride
{
    /**
     * When set, replaces the template's from_email in the envelope. Null means
     * "use the template's own from_email" (e.g. the admin live preview).
     */
    public ?string $fromAddressOverride = null;

    public function usingFromAddress(?string $address): static
    {
        $this->fromAddressOverride = ($address !== null && $address !== '') ? $address : null;

        return $this;
    }
}
