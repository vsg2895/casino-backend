<?php

declare(strict_types=1);

namespace App\Support\Media;

use Illuminate\Support\Facades\Storage;

/**
 * Pixel size of an image this application stores on the `public` disk.
 *
 * The sites need it to decide whether a cover is large enough to be the
 * page's share image: Google Discover and large link previews want at least
 * 1200px wide, and an og:image declared without its size, or one too small,
 * is either cropped badly or ignored.
 *
 * Deliberately narrow. Null for:
 * - an absolute URL: somebody else's server, never fetched from here;
 * - an SVG: no intrinsic pixel size, and no share target renders one anyway;
 * - a missing or unreadable file, or a disk that is not local.
 *
 * Reads only the file's header (getimagesize), not the whole image.
 */
final class ImageDimensions
{
    /** @return array{width: int, height: int}|null */
    public static function forPublicPath(?string $path): ?array
    {
        $path = ltrim(trim((string) $path), '/');

        if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 || str_contains($path, '..')) {
            return null;
        }

        if (str_ends_with(mb_strtolower($path), '.svg') || config('filesystems.disks.public.driver') !== 'local') {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $size = @getimagesize($disk->path($path));

        if ($size === false || $size[0] <= 0 || $size[1] <= 0) {
            return null;
        }

        return ['width' => $size[0], 'height' => $size[1]];
    }
}
