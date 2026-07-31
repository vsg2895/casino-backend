<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Contracts\Mail\Mailer;

/**
 * Value object bundling a resolved transport with the From address to use for a
 * promotion batch, so the sending job stays agnostic of provider details.
 */
final readonly class ResolvedPromotionMailer
{
    public function __construct(
        public Mailer $mailer,
        public ?string $fromAddress,
    ) {
    }
}
