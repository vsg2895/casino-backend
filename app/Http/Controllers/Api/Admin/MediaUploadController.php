<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadMediaRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaUploadController extends Controller
{
    /**
     * Accept an uploaded image, normalise it to WebP (downscaled), store on the
     * public disk, and return the stored path + public URL. The admin saves the
     * returned path into image_path / banner_image columns.
     *
     * Uses native PHP GD for conversion (always available, no library version
     * coupling). Falls back to storing the original bytes if GD cannot decode.
     */
    public function store(UploadMediaRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $type = $request->input('type', 'image');
        $maxWidth = $type === 'banner' ? 1600 : 800;

        $bytes = $this->toWebp($file->getRealPath(), $maxWidth);

        if ($bytes !== null) {
            $path = 'uploads/' . $type . '/' . Str::uuid()->toString() . '.webp';
            Storage::disk('public')->put($path, $bytes);
        } else {
            // Fallback: keep the original file as-is.
            $path = $file->store('uploads/' . $type, 'public');
        }

        return response()->json([
            'path' => $path,
            'url'  => Storage::disk('public')->url($path),
        ], 201);
    }

    /** Convert an image file to WebP bytes, downscaling to $maxWidth. Returns null on failure. */
    private function toWebp(string $sourcePath, int $maxWidth): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($sourcePath));
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        if ($width > $maxWidth) {
            $scaled = imagescale($image, $maxWidth);
            if ($scaled !== false) {
                imagedestroy($image);
                $image = $scaled;
            }
        }

        imagepalettetotruecolor($image);

        ob_start();
        imagewebp($image, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes !== '' ? $bytes : null;
    }
}
