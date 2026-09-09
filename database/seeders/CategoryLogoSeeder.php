<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Support\Media\SvgSanitizer;
use App\Support\SiteCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Writes the category logos to the public disk and points `logo_path` at them.
 *
 * The artwork is EMBEDDED here rather than read from a directory, so the seeder
 * is self-contained: nothing has to be uploaded to the server first, and the
 * same command produces the same icons on every environment.
 *
 * Idempotent in two ways. Filenames are derived from the slug, so re-running
 * overwrites the same file instead of accumulating orphaned UUIDs; and a
 * category that already has a `logo_path` is SKIPPED, so an icon someone
 * uploaded through the admin panel is never silently replaced. Pass `--force`
 * to overwrite anyway.

 *
 * Every file goes through {@see SvgSanitizer} — the same pass the admin upload
 * uses. These strings are ours, but routing them through the one sanitiser is
 * what keeps "SVG reaching disk" a single audited path rather than two.
 *
 *   php artisan db:seed --class=CategoryLogoSeeder
 *   SEED_REPLACE=1 php artisan db:seed --class=CategoryLogoSeeder --force
 */
class CategoryLogoSeeder extends Seeder
{
    private const string DIRECTORY = 'uploads/category-logos';

    public function run(): void
    {
        // An ENV VAR, not a CLI flag. `db:seed` has no pass-through for extra
        // options — a trailing `--replace` is parsed as a second seeder CLASS
        // and fails with "Target class [Database\\Seeders\\--replace] does not
        // exist" — and reading `--force` would collide with Laravel's own
        // production-confirmation flag, which you always pass in production.
        $replace = filter_var(env('SEED_REPLACE', false), FILTER_VALIDATE_BOOLEAN);

        $written = 0;
        $skipped = 0;
        $missing = [];

        foreach ($this->logos() as $slug => $svg) {
            $category = Category::where('slug', $slug)->first();

            if ($category === null) {
                $missing[] = $slug;

                continue;
            }

            if ($category->logo_path && ! $replace) {
                $this->command?->line("  skip   {$category->name} — already has a logo (SEED_REPLACE=1 to overwrite)");
                $skipped++;

                continue;
            }

            $clean = SvgSanitizer::clean($svg);

            if ($clean === null) {
                $this->command?->error("  FAILED {$slug} — did not survive sanitising");

                continue;
            }

            $path = self::DIRECTORY . '/' . $slug . '.svg';
            Storage::disk('public')->put($path, $clean);
            $category->update(['logo_path' => $path]);

            $this->command?->line("  write  {$category->name} -> {$path}");
            $written++;
        }

        if ($missing !== []) {
            $this->command?->warn('  No category for: ' . implode(', ', $missing) . ' (skipped)');
        }

        $this->refresh();

        $this->command?->info("Logos written: {$written}, skipped: {$skipped}.");
    }

    /**
     * Flush every site and ask it to rebuild.
     *
     * Category logos render in the nav chips and on /categories for every site,
     * so all of them are stale — without this they keep serving the old markup
     * until the ISR window expires.
     */
    private function refresh(): void
    {
        $siteIds = DB::table('sites')->where('active', true)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite($siteId);
        }

        if ($siteIds !== []) {
            \App\Jobs\RevalidateNextJsSites::dispatch(['categories', 'casinos'], $siteIds);
            $this->command?->line('  revalidated site(s): ' . implode(', ', $siteIds));
        }
    }

    /** @return array<string, string> slug => SVG source */
    private function logos(): array
    {
        return [
        'most-popular' => <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img">
 <defs><linearGradient id="mp" x1="0" y1="0" x2="1" y2="1">
 <stop offset="0" stop-color="#16A34A"/><stop offset="1" stop-color="#052E16"/>
 </linearGradient></defs>
 <!-- Deep forest, and deliberately DARK. The active chip is a mid-tone emerald
 pill, so a light green tile sat on it with almost no separation. Going
 darker than the pill — rather than lighter — is what gives the tile an
 edge on both the white chip and the selected one.
 The wide 600950 range is doing work too: a flatter ramp reads as a solid
 block, this one keeps some dimension at 32px. -->
 <rect width="24" height="24" rx="7" fill="url(#mp)"/>
 <path d="M12 5.6l1.83 3.7 4.09.6-2.96 2.88.7 4.07L12 14.93l-3.66 1.92.7-4.07-2.96-2.88 4.09-.6z" fill="#fff"/>
</svg>
SVG,
        'best-bonuses' => <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img">
 <defs><linearGradient id="bb" x1="0" y1="0" x2="1" y2="1">
 <stop offset="0" stop-color="#A78BFA"/><stop offset="1" stop-color="#EC4899"/>
 </linearGradient></defs>
 <rect width="24" height="24" rx="7" fill="url(#bb)"/>
 <path d="M5.6 11.2h12.8v6.1a1 1 0 0 1-1 1H6.6a1 1 0 0 1-1-1z" fill="#fff" opacity=".92"/>
 <rect x="5" y="9.1" width="14" height="2.9" rx="1" fill="#fff"/>
 <rect x="10.9" y="9.1" width="2.2" height="9.2" fill="url(#bb)"/>
 <path d="M12 9.1S11 5.6 9.2 5.6a1.7 1.7 0 0 0 0 3.5zM12 9.1s1-3.5 2.8-3.5a1.7 1.7 0 0 1 0 3.5z" fill="#fff"/>
</svg>
SVG,
        'new-casinos' => <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img">
 <defs><linearGradient id="nc" x1="0" y1="0" x2="1" y2="1">
 <stop offset="0" stop-color="#38BDF8"/><stop offset="1" stop-color="#4F46E5"/>
 </linearGradient></defs>
 <rect width="24" height="24" rx="7" fill="url(#nc)"/>
 <path d="M10.2 5.2l1.32 3.26L14.8 9.8l-3.28 1.34L10.2 14.4 8.88 11.14 5.6 9.8l3.28-1.34z" fill="#fff"/>
 <path d="M16.1 13.1l.72 1.78 1.78.72-1.78.72-.72 1.78-.72-1.78-1.78-.72 1.78-.72z" fill="#fff" opacity=".9"/>
</svg>
SVG,
        'free-spins' => <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img">
 <defs>
 <linearGradient id="fsblk" x1="0" y1="0" x2="1" y2="1">
 <stop offset="0" stop-color="#27272A"/><stop offset="1" stop-color="#000000"/>
 </linearGradient>
 <linearGradient id="fsgold" x1="0" y1="0" x2="0" y2="1">
 <stop offset="0" stop-color="#FDE68A"/><stop offset="1" stop-color="#D97706"/>
 </linearGradient>
 </defs>
 <!-- Near-black rather than flat #000: a pure black tile goes dead against the
 white card, whereas a 2700 ramp keeps a readable edge and a little
 dimension at 32px. -->
 <rect width="24" height="24" rx="7" fill="url(#fsblk)"/>
 <!-- Crown and reel frame both gold, on one vertical ramp so they read as a
 single piece of metal rather than two unrelated gold shapes. -->
 <path d="M5.4 9.6L6.6 4.6l2.6 2.9L12 3.4l2.8 4.1 2.6-2.9 1.2 5z" fill="url(#fsgold)"/>
 <rect x="4.6" y="11" width="14.8" height="8.4" rx="2.2" fill="url(#fsgold)"/>
 <!-- Reels knocked back out to the tile colour. -->
 <rect x="6.3" y="12.6" width="3.3" height="5.2" rx="1" fill="#0A0A0A"/>
 <rect x="10.35" y="12.6" width="3.3" height="5.2" rx="1" fill="#0A0A0A"/>
 <rect x="14.4" y="12.6" width="3.3" height="5.2" rx="1" fill="#0A0A0A"/>
</svg>
SVG,
        'betting' => <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img">
 <defs><linearGradient id="bt" x1="0" y1="0" x2="1" y2="1">
 <stop offset="0" stop-color="#FB7185"/><stop offset="1" stop-color="#BE123C"/>
 </linearGradient></defs>
 <rect width="24" height="24" rx="7" fill="url(#bt)"/>
 <ellipse cx="12" cy="9.1" rx="5.5" ry="2.25" fill="#fff"/>
 <path d="M6.5 11.9c0 1.24 2.46 2.25 5.5 2.25s5.5-1.01 5.5-2.25" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/>
 <path d="M6.5 14.7c0 1.24 2.46 2.25 5.5 2.25s5.5-1.01 5.5-2.25" fill="none" stroke="#fff" stroke-width="1.7" stroke-linecap="round"/>
</svg>
SVG,
        'online-casinos' => <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" role="img">
 <defs><linearGradient id="oc" x1="0" y1="0" x2="1" y2="1">
 <stop offset="0" stop-color="#60A5FA"/><stop offset="1" stop-color="#1E3A8A"/>
 </linearGradient></defs>
 <rect width="24" height="24" rx="7" fill="url(#oc)"/>
 <rect x="4.9" y="5.9" width="14.2" height="9.7" rx="1.7" fill="#fff"/>
 <path d="M12 8.15c-1.15 1.25-2.4 2.05-2.4 3.2a1.45 1.45 0 0 0 2.1 1.25c-.12.5-.42.9-.85 1.1h2.3c-.43-.2-.73-.6-.85-1.1a1.45 1.45 0 0 0 2.1-1.25c0-1.15-1.25-1.95-2.4-3.2z" fill="url(#oc)"/>
 <path d="M12 15.6v2.1M9.5 17.9h5" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>
</svg>
SVG,
        ];
    }
}
