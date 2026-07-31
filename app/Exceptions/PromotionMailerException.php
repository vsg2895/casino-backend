<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a promotion campaign's chosen delivery transport can't be built —
 * e.g. the schedule points at a SendGrid key that has since been disabled or
 * deleted. Callers catch this to fail the batch GRACEFULLY (log + skip) rather
 * than crash the queue worker.
 */
class PromotionMailerException extends RuntimeException
{
}
