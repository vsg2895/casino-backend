<?php

declare(strict_types=1);

namespace App\Perfume\Http\Resources;

use Illuminate\Support\Facades\Storage;

/** A stored path on the public disk → absolute URL, or null. */
final class ImageUrl
{
    public static function of(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
