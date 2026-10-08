<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Support\Media\SvgSanitizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Adds the "Crypto Oriented" casino category, with its logo.
 *
 *   php artisan db:seed --class=CryptoOrientedCategorySeeder --force
 *
 * ── Why a seeder and not a migration ────────────────────────────────────────
 *
 * This is CONTENT, not schema. A category is a row an editor can rename, reorder
 * or delete from the admin, and a migration that recreated it on the next deploy
 * would be fighting them. Running this is a deliberate act, once.
 *
 * ── Why the SVG is embedded here ────────────────────────────────────────────
 *
 * `logo_path` points into `storage/app/public`, which is NOT version-controlled
 * and is not carried by a deploy. Creating only the row would give production a
 * category whose logo 404s. So the bytes travel with the seeder and are written
 * through the same {@see SvgSanitizer} the admin's upload endpoint uses — an SVG
 * is an executable document served from the API origin, and "we wrote this one
 * ourselves" is not a reason to let unchecked markup onto that origin.
 *
 * ── What it will not do ─────────────────────────────────────────────────────
 *
 * Idempotent, and deliberately conservative about everything an editor might
 * have touched:
 *
 *  - The category is matched by SLUG. A second run finds the existing row.
 *  - `name` and `sort_order` are set only when the row is created. Renaming the
 *    category or moving it up the list is an editorial decision this must not
 *    undo.
 *  - `logo_path` is filled only when it is empty — a logo swapped in the admin
 *    survives.
 *  - The file is written only when that path does not already exist, so a
 *    re-upload is never overwritten by a re-run.
 *
 * ── What it cannot do for you ───────────────────────────────────────────────
 *
 * A category with no casinos attached is invisible on every public site: the
 * public endpoint only returns categories holding at least one active casino for
 * that site, which is what stops a chip leading to an empty list. Attach casinos
 * in the admin afterwards.
 */
class CryptoOrientedCategorySeeder extends Seeder
{
    private const string SLUG = 'crypto-oriented';
    private const string NAME = 'Crypto Oriented';
    private const string LOGO_PATH = 'uploads/category-logos/crypto-oriented.svg';

    /** Where this category sits in the chip row when it is first created. */
    private const int SORT_ORDER = 5;

    public function run(): void
    {
        $this->writeLogo();

        $category = Category::query()->where('slug', self::SLUG)->first();

        if ($category === null) {
            $category = Category::create([
                'name'       => self::NAME,
                'slug'       => self::SLUG,
                'logo_path'  => self::LOGO_PATH,
                'sort_order' => self::SORT_ORDER,
            ]);

            $this->command?->info("Created category \"{$category->name}\" (id {$category->id}).");
            $this->command?->comment('It stays invisible on the public sites until casinos are attached to it.');

            return;
        }

        $this->command?->info("Category \"{$category->name}\" already exists (id {$category->id}); left as it is.");

        // The one repair worth making on an existing row: a category carrying no
        // logo renders a gap in the chip row, and the file is now on disk.
        if ((string) $category->logo_path === '') {
            $category->update(['logo_path' => self::LOGO_PATH]);
            $this->command?->info('Filled its empty logo_path.');
        }
    }

    /**
     * Write the logo to the public disk, unless something is already there.
     *
     * Sanitised on the way in, exactly as an admin upload is. A refusal aborts
     * the whole seeder: a category pointing at a file that was never written is
     * worse than no category at all, because the gap only shows up in a browser.
     */
    private function writeLogo(): void
    {
        $disk = Storage::disk('public');

        if ($disk->exists(self::LOGO_PATH)) {
            $this->command?->comment('Logo already on disk; not overwritten.');

            return;
        }

        $clean = SvgSanitizer::clean($this->logoSvg());

        if ($clean === null) {
            throw new \RuntimeException('The embedded Crypto Oriented logo did not survive SVG sanitising.');
        }

        $disk->put(self::LOGO_PATH, $clean);
        $this->command?->info('Wrote ' . self::LOGO_PATH . ' to the public disk.');
    }

    /**
     * The mark: a white coin on an amber-to-orange tile, with the ₿ knocked out
     * of the coin in the tile's own gradient.
     *
     * Same construction as the other six category logos — a 24x24 box, one
     * gradient tile at rx=7, a white glyph — so the row of chips reads as one
     * set. Amber/orange is the one corner of that palette no other category
     * uses. The mark is CUT OUT rather than stroked on top because these render
     * at 32px, where orange strokes that thin turn to mush on white.
     */
    private function logoSvg(): string
    {
        return <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img">
             <defs><linearGradient id="co" x1="0" y1="0" x2="1" y2="1">
             <stop offset="0" stop-color="#FBBF24"/><stop offset="1" stop-color="#EA580C"/>
             </linearGradient></defs>
             <rect width="24" height="24" rx="7" fill="url(#co)"/>
             <circle cx="12" cy="12" r="6.7" fill="#fff"/>
             <rect x="10.4" y="6.5" width="1.25" height="2.2" rx="0.45" fill="url(#co)"/>
             <rect x="12.5" y="6.5" width="1.25" height="2.2" rx="0.45" fill="url(#co)"/>
             <rect x="10.4" y="15.3" width="1.25" height="2.2" rx="0.45" fill="url(#co)"/>
             <rect x="12.5" y="15.3" width="1.25" height="2.2" rx="0.45" fill="url(#co)"/>
             <rect x="9.1" y="8.1" width="1.5" height="7.8" rx="0.5" fill="url(#co)"/>
             <path d="M10.3 8.1h3.1a1.85 1.85 0 0 1 0 3.7h-3.1z" fill="url(#co)"/>
             <path d="M10.3 12.2h3.5a1.9 1.9 0 0 1 0 3.7h-3.5z" fill="url(#co)"/>
            </svg>
            SVG;
    }
}
