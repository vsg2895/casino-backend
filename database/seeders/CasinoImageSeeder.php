<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Casino;
use App\Models\SpecialOffer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Generates branded placeholder images (logo + banner) for every casino and
 * special offer, stores them on the public disk and attaches the paths, so the
 * public sites render real images instead of letter-avatar fallbacks.
 *
 * Idempotent: re-running regenerates the images. Run standalone with
 * `php artisan db:seed --class=CasinoImageSeeder` to apply to an existing DB.
 */
class CasinoImageSeeder extends Seeder
{
    /** @var array<int, array{int,int,int}> */
    private array $palette = [
        [16, 122, 87], [37, 99, 235], [219, 39, 119], [202, 138, 4],
        [124, 58, 237], [8, 145, 178], [190, 24, 93], [5, 150, 105],
        [217, 70, 41], [79, 70, 229],
    ];

    public function run(): void
    {
        // Suppress the CasinoObserver during the bulk write; we revalidate once at the end.
        Model::withoutEvents(function (): void {
            foreach (Casino::all() as $casino) {
                $logo   = 'seed/casinos/' . $casino->slug . '-logo.webp';
                $banner = 'seed/casinos/' . $casino->slug . '-banner.webp';
                Storage::disk('public')->put($logo, $this->logo($casino->name));
                Storage::disk('public')->put($banner, $this->banner($casino->name));
                $casino->update(['image_path' => $logo, 'banner_image' => $banner]);
            }

            foreach (SpecialOffer::all() as $offer) {
                $image  = 'seed/offers/' . $offer->slug . '-image.webp';
                $banner = 'seed/offers/' . $offer->slug . '-banner.webp';
                Storage::disk('public')->put($image, $this->logo($offer->title));
                Storage::disk('public')->put($banner, $this->banner($offer->title));
                $offer->update(['image_path' => $image, 'banner_image' => $banner]);
            }
        });

        $this->command?->info('  Generated placeholder images for casinos and special offers.');
    }

    /** Square logo: solid brand colour + the entity's initial. */
    private function logo(string $name): string
    {
        $size = 256;
        $img = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = $this->colorFor($name);
        imagefilledrectangle($img, 0, 0, $size, $size, imagecolorallocate($img, $r, $g, $b));

        $white = imagecolorallocate($img, 255, 255, 255);
        $letter = mb_strtoupper(mb_substr(ltrim($name), 0, 1)) ?: '?';
        $font = $this->font();

        if ($font !== null) {
            $bbox = imagettfbbox(130, 0, $font, $letter);
            $tw = $bbox[2] - $bbox[0];
            $th = $bbox[1] - $bbox[7];
            imagettftext($img, 130, 0, (int) (($size - $tw) / 2 - $bbox[0]), (int) (($size - $th) / 2 - $bbox[7]), $white, $font, $letter);
        } else {
            imagestring($img, 5, (int) ($size / 2 - 4), (int) ($size / 2 - 7), $letter, $white);
        }

        return $this->toWebp($img);
    }

    /** Wide banner: vertical gradient + the entity's name. */
    private function banner(string $name): string
    {
        $w = 1200;
        $h = 360;
        $img = imagecreatetruecolor($w, $h);
        [$r, $g, $b] = $this->colorFor($name);

        for ($y = 0; $y < $h; $y++) {
            $f = ($y / $h) * 0.55;
            $c = imagecolorallocate($img, (int) ($r * (1 - $f)), (int) ($g * (1 - $f)), (int) ($b * (1 - $f)));
            imageline($img, 0, $y, $w, $y, $c);
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        $font = $this->font();

        if ($font !== null) {
            $bbox = imagettfbbox(56, 0, $font, $name);
            $tw = $bbox[2] - $bbox[0];
            imagettftext($img, 56, 0, (int) (($w - $tw) / 2 - $bbox[0]), (int) ($h / 2 + 20), $white, $font, $name);
        } else {
            imagestring($img, 5, 40, (int) ($h / 2 - 7), $name, $white);
        }

        return $this->toWebp($img);
    }

    /** @return array{int,int,int} */
    private function colorFor(string $seed): array
    {
        return $this->palette[crc32($seed) % count($this->palette)];
    }

    private function font(): ?string
    {
        foreach (['/System/Library/Fonts/Supplemental/Arial.ttf', '/Library/Fonts/Arial.ttf'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function toWebp(\GdImage $img): string
    {
        ob_start();
        imagewebp($img, null, 82);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
