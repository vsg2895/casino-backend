<?php

declare(strict_types=1);

namespace App\Services\IpInfo;

/**
 * Resolves nothing, successfully.
 *
 * The default driver, and the one the test suite uses. It exists so that
 * "no provider configured" is an ordinary, supported state rather than an
 * error path: visits are still recorded with every address they carried, they
 * simply have no geography attached. That is also what keeps the suite from
 * ever making a billable call by accident.
 */
final class NullIpInfoDriver implements IpInfoDriver
{
    public function lookup(string $ip): ?array
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}
