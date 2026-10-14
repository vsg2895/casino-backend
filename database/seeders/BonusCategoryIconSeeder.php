<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BonusCategory;
use App\Support\Media\SvgSanitizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The icon for every bonus type, and any type that is missing.
 *
 *   php artisan db:seed --class=BonusCategoryIconSeeder --force
 *
 * ── What this is for ────────────────────────────────────────────────────────
 *
 * The home page now opens with a row of chips, one per bonus type, each showing
 * how many bonuses it holds and leading to that type's own page. A chip needs a
 * mark; `bonus_categories.icon_path` is where it lives, and this is what fills
 * it.
 *
 * ── Why a seeder and not a migration ────────────────────────────────────────
 *
 * This is CONTENT. A bonus type is a row an editor renames, reorders, hides or
 * deletes from the admin, and an icon is a picture they can replace there.
 * A migration that recreated either on the next deploy would be fighting them.
 * Running this is a deliberate act, once. The migration only adds the column.
 *
 * ── Why the SVGs are embedded here ──────────────────────────────────────────
 *
 * `icon_path` points into `storage/app/public`, which is not version-controlled
 * and is not carried by a deploy, so a row created without its file would be a
 * category whose icon 404s. The bytes travel with the seeder and go through the
 * same {@see SvgSanitizer} the admin's upload endpoint uses — an SVG is an
 * executable document served from the API origin, and "we wrote this one
 * ourselves" is not a reason to let unchecked markup onto that origin.
 *
 * Its own directory, NOT `uploads/category-logos`: a casino category and a bonus
 * type can share a slug — `free-spins` and `special-offers` already exist as
 * both — and two files named for the same slug in one directory is a collision
 * waiting for whichever seeder runs second.
 *
 * ── What it will not do ─────────────────────────────────────────────────────
 *
 * Idempotent, and deliberately conservative about everything an editor may have
 * touched:
 *
 *  - Types are matched by SLUG, so a second run finds the existing rows.
 *  - `name`, `position`, `description` and `active` are set only when the row is
 *    CREATED. Renaming a type, reordering it or hiding it is an editorial
 *    decision this must not undo.
 *  - `icon_path` is filled only when it is empty, so an icon swapped in the
 *    admin survives.
 *  - A file is written only when that path does not already exist, so a
 *    re-upload is never overwritten by a re-run.
 *
 * On production — where the first eight types already exist — this creates only
 * what is missing and hands every row its icon.
 *
 * ── What it cannot do for you ───────────────────────────────────────────────
 *
 * A type with no visible offers on a site is not rendered there at all: the
 * public endpoint drops empty headings, which is what stops a chip leading to an
 * empty page. "Free Spins", "Reload Bonuses" and "No Deposit Offers" are at zero
 * offers today, so they stay invisible until offers are filed under them.
 */
class BonusCategoryIconSeeder extends Seeder
{
    /** Where the icons live on the public disk. */
    private const string ICON_DIR = 'uploads/bonus-category-icons/';

    public function run(): void
    {
        $created = 0;
        $iconed = 0;

        foreach ($this->types() as $slug => $type) {
            $path = self::ICON_DIR . $slug . '.svg';

            $this->writeIcon($path, $type['svg']);

            $category = BonusCategory::query()->where('slug', $slug)->first();

            if ($category === null) {
                $category = BonusCategory::create([
                    'name'        => $type['name'],
                    'description' => $type['description'],
                    'icon_path'   => $path,
                    'position'    => $type['position'],
                    'active'      => true,
                ]);

                $created++;
                $this->command?->info("Created bonus type \"{$category->name}\" (id {$category->id}).");

                continue;
            }

            if ((string) $category->icon_path === '') {
                $category->update(['icon_path' => $path]);
                $iconed++;
            }
        }

        $this->command?->info("Done. {$created} type(s) created, {$iconed} icon(s) attached.");
        $this->command?->comment('Types with no visible offers on a site stay hidden there — that is the rule that keeps a chip from leading nowhere.');
    }

    /**
     * Write one icon, unless something is already at that path.
     *
     * A refusal aborts the whole seeder: a row pointing at a file that was never
     * written is worse than no row at all, because the gap only shows up in a
     * browser.
     */
    private function writeIcon(string $path, string $svg): void
    {
        $disk = Storage::disk('public');

        if ($disk->exists($path)) {
            return;
        }

        $clean = SvgSanitizer::clean($svg);

        if ($clean === null) {
            throw new \RuntimeException("The embedded icon for {$path} did not survive SVG sanitising.");
        }

        $disk->put($path, $clean);
        $this->command?->info('Wrote ' . $path);
    }

    /**
     * The types, in the order production has them, each with its mark.
     *
     * ── How the set is drawn ────────────────────────────────────────────────
     *
     * One construction for all of them, and the same one the casino-category
     * logos use: a 24x24 box, a single gradient tile at rx=7, a white glyph cut
     * into it. That is what makes a row of chips read as ONE set rather than as
     * eight icons that happen to sit together — and it is why the artwork
     * carries its own background, so the same file works on a white chip and on
     * a saturated one without a filter.
     *
     * Every type gets its own corner of the palette, because the colour is the
     * fastest way to tell two chips apart before either label is read. No two
     * glyphs share a silhouette either: a reader scanning the row at a glance is
     * reading shapes, not pictures.
     *
     * Each gradient id is scoped to its own FILE — these are served as separate
     * documents through `<img src>`, so ids cannot collide the way eight inline
     * SVGs on one page would.
     *
     * @return array<string, array{name: string, description: string|null, position: int, svg: string}>
     */
    private function types(): array
    {
        return [
            // A gift box with its ribbon knocked out — the welcome present.
            'welcome-bonuses' => [
                'name'        => 'Welcome Bonuses',
                'description' => null,
                'position'    => 1,
                'svg'         => $this->tile('wb', '#34D399', '#0D9488', <<<'G'
                     <rect x="4" y="10.2" width="16" height="9.3" rx="1.6" fill="#fff"/>
                     <rect x="2.9" y="6.9" width="18.2" height="3.9" rx="1.3" fill="#fff"/>
                     <rect x="10.8" y="6.9" width="2.4" height="12.6" fill="url(#wb)"/>
                     <path d="M11.6 6.6C10.2 6.6 8.2 6.3 7.5 5.4a1.7 1.7 0 0 1 2.3-2.3c1 .7 1.6 2.3 1.8 3.5z" fill="#fff"/>
                     <path d="M12.4 6.6c1.4 0 3.4-.3 4.1-1.2a1.7 1.7 0 0 0-2.3-2.3c-1 .7-1.6 2.3-1.8 3.5z" fill="#fff"/>
                    G),
            ],

            /*
             * A coin carrying a percent — "10% back", the way the offer itself
             * is worded. The first drawing was a coin with a return arrow
             * curling out of it, which at 22px read as a spiral and, worse, as
             * a near-twin of Reload's refresh arc two chips along.
             */
            'cashback' => [
                'name'        => 'Cashback',
                'description' => null,
                'position'    => 2,
                'svg'         => $this->tile('cb', '#A78BFA', '#6D28D9', <<<'G'
                     <circle cx="12" cy="12" r="7.1" fill="#fff"/>
                     <rect x="11.1" y="7.4" width="1.8" height="9.2" rx="0.9" transform="rotate(35 12 12)" fill="url(#cb)"/>
                     <circle cx="9.7" cy="9.7" r="1.65" fill="url(#cb)"/>
                     <circle cx="14.3" cy="14.3" r="1.65" fill="url(#cb)"/>
                    G),
            ],

            // A trophy. The one mark nobody has to read a label to understand.
            'tournament' => [
                'name'        => 'Tournament',
                'description' => null,
                'position'    => 3,
                'svg'         => $this->tile('tn', '#FBBF24', '#EA580C', <<<'G'
                     <path d="M8.1 4.6h7.8v4.2a3.9 3.9 0 0 1-7.8 0z" fill="#fff"/>
                     <path d="M8.1 5.6H6.3a2.6 2.6 0 0 0 2.3 3.9M15.9 5.6h1.8a2.6 2.6 0 0 1-2.3 3.9" fill="none" stroke="#fff" stroke-width="1.35" stroke-linecap="round"/>
                     <rect x="11" y="12.1" width="2" height="3.4" fill="#fff"/>
                     <rect x="7.9" y="15.3" width="8.2" height="2.2" rx="1.1" fill="#fff"/>
                    G),
            ],

            /*
             * Paying IN: an arrow down into an open box.
             *
             * The first drawing was a coin above a filled tray, and a disc
             * sitting on a wide arc is the silhouette of an avatar — the chip
             * read as "My account" at a glance. An open outline with a solid
             * arrow through it cannot be mistaken for a head and shoulders.
             */
            'deposit-bonuses' => [
                'name'        => 'Deposit Bonuses',
                'description' => 'Offers after deposits',
                'position'    => 4,
                'svg'         => $this->tile('db', '#38BDF8', '#2563EB', <<<'G'
                     <rect x="11.05" y="4.8" width="1.9" height="6.4" rx="0.95" fill="#fff"/>
                     <path d="M12 14.1 8.3 10h7.4z" fill="#fff"/>
                     <path d="M5.6 13.3v3.2a2.4 2.4 0 0 0 2.4 2.4h8a2.4 2.4 0 0 0 2.4-2.4v-3.2" fill="none" stroke="#fff" stroke-width="1.9" stroke-linecap="round"/>
                    G),
            ],

            // A wheel mid-spin: four wedges and a hub.
            'free-spins' => [
                'name'        => 'Free Spins',
                'description' => 'Offers whose headline is spins rather than cash.',
                'position'    => 5,
                'svg'         => $this->tile('fs', '#F0ABFC', '#DB2777', <<<'G'
                     <circle cx="12" cy="12" r="7.2" fill="#fff"/>
                     <path d="M12 12V4.8a7.2 7.2 0 0 1 7.2 7.2z" fill="url(#fs)"/>
                     <path d="M12 12h7.2a7.2 7.2 0 0 1-7.2 7.2z" fill="url(#fs)" opacity=".45"/>
                     <path d="M12 12v7.2A7.2 7.2 0 0 1 4.8 12z" fill="url(#fs)"/>
                     <path d="M12 12H4.8A7.2 7.2 0 0 1 12 4.8z" fill="url(#fs)" opacity=".45"/>
                     <circle cx="12" cy="12" r="2.1" fill="#fff"/>
                    G),
            ],

            // The refresh arc — topping an account back up.
            'reload-bonuses' => [
                'name'        => 'Reload Bonuses',
                'description' => null,
                'position'    => 6,
                'svg'         => $this->tile('rb', '#67E8F9', '#0891B2', <<<'G'
                     <path d="M18.4 12a6.4 6.4 0 1 1-2.3-4.9" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>
                     <path d="M17.6 4.6v4.2h-4.2z" fill="#fff"/>
                     <circle cx="12" cy="12" r="2.1" fill="#fff"/>
                    G),
            ],

            // A coin struck through: nothing to pay in.
            'no-deposit-offers' => [
                'name'        => 'No Deposit Offers',
                'description' => null,
                'position'    => 7,
                'svg'         => $this->tile('nd', '#FDA4AF', '#E11D48', <<<'G'
                     <circle cx="12" cy="12" r="7.1" fill="#fff"/>
                     <rect x="10.85" y="5.6" width="2.3" height="12.8" rx="1.15" transform="rotate(45 12 12)" fill="url(#nd)"/>
                    G),
            ],

            /*
             * A ₿ coin: bonuses that exist because the deposit was in crypto.
             *
             * Lime→emerald rather than Bitcoin's own orange, which Tournament
             * already owns — in a row of chips the colour is read before the
             * glyph, and two amber tiles would be two chips a reader has to
             * stop and tell apart. The casino CATEGORY of the same name keeps
             * its amber tile: different table, different icon directory, and
             * the two never appear in the same row.
             */
            'crypto-oriented' => [
                'name'        => 'Crypto Oriented',
                'description' => 'Offers for players who deposit in crypto.',
                'position'    => 9,
                'svg'         => $this->tile('cr', '#A3E635', '#059669', <<<'G'
                     <circle cx="12" cy="12" r="7.1" fill="#fff"/>
                     <rect x="10.4" y="5.6" width="1.3" height="2.3" rx="0.45" fill="url(#cr)"/>
                     <rect x="12.6" y="5.6" width="1.3" height="2.3" rx="0.45" fill="url(#cr)"/>
                     <rect x="10.4" y="16.1" width="1.3" height="2.3" rx="0.45" fill="url(#cr)"/>
                     <rect x="12.6" y="16.1" width="1.3" height="2.3" rx="0.45" fill="url(#cr)"/>
                     <rect x="8.9" y="7.4" width="1.6" height="9.2" rx="0.5" fill="url(#cr)"/>
                     <path d="M10.2 7.4h3.3a1.95 1.95 0 0 1 0 3.9h-3.3z" fill="url(#cr)"/>
                     <path d="M10.2 11.7h3.7a2 2 0 0 1 0 4h-3.7z" fill="url(#cr)"/>
                    G),
            ],

            // A star: the catch-all heading, and the one the Claim button reads.
            'special-offers' => [
                'name'        => 'Special Offers',
                'description' => null,
                'position'    => 8,
                'svg'         => $this->tile('so', '#818CF8', '#4338CA', <<<'G'
                     <path d="m12 4.4 2.36 4.79 5.28.77-3.82 3.72.9 5.26L12 16.46l-4.72 2.48.9-5.26-3.82-3.72 5.28-.77z" fill="#fff"/>
                     <path d="m18.8 3.2.62 1.38 1.38.62-1.38.62-.62 1.38-.62-1.38-1.38-.62 1.38-.62z" fill="#fff" opacity=".8"/>
                    G),
            ],
        ];
    }

    /**
     * One icon: the gradient tile, then the glyph.
     *
     * The tile is identical in every file — only the two stops and the glyph
     * change — so the set cannot drift out of alignment one icon at a time.
     */
    private function tile(string $id, string $from, string $to, string $glyph): string
    {
        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img">
             <defs><linearGradient id="{$id}" x1="0" y1="0" x2="1" y2="1">
             <stop offset="0" stop-color="{$from}"/><stop offset="1" stop-color="{$to}"/>
             </linearGradient></defs>
             <rect width="24" height="24" rx="7" fill="url(#{$id})"/>
            {$glyph}
            </svg>
            SVG;
    }
}
