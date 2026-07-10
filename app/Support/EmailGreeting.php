<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds the optional "Dear {name}," salutation shown as the first body line of
 * subscription / verify / promotion emails. Returns an empty string when no name
 * was captured, so the blade renders no greeting at all.
 */
final class EmailGreeting
{
    public static function line(?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '' : 'Dear ' . $name . ',';
    }
}
