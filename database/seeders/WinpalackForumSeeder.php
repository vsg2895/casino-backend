<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NavItem;
use App\Models\Site;
use App\Support\SiteCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Hands winpalack's menu and forum page over to the admin panel.
 *
 * WHY THE WHOLE MENU AND NOT JUST "FORUM": the layout treats admin navigation as
 * all-or-nothing — one nav item in the database replaces the entire menu that
 * lives in code. Seeding "Forum" alone would therefore delete Casinos, Special
 * Offers, Categories and Countries from the header. The five links below are the
 * menu that renders today, so after this seeder the site looks identical and
 * every item is editable in Sites → Navigation.
 *
 * "Guides" is deliberately absent: that link appears only once the site has
 * three published articles, and a hand-seeded row would bypass the threshold and
 * point at a section that is not open yet.
 *
 * IDEMPOTENT. Winpalack's rows are deleted and rewritten on every run, so this
 * can be re-run after a schema change without producing a duplicate menu. It
 * touches NO other site — the other five keep rendering their menu from code.
 */
class WinpalackForumSeeder extends Seeder
{
    private const string SLUG = 'winpalack';

    /** The menu that currently renders from code, in its current order. */
    private const array HEADER = [
        ['label' => 'Casinos',        'url' => '/casinos'],
        ['label' => 'Special Offers', 'url' => '/special-offers'],
        ['label' => 'Categories',     'url' => '/categories'],
        ['label' => 'Countries',      'url' => '/countries'],
        ['label' => 'Forum',          'url' => '/forum'],
    ];

    public function run(): void
    {
        $site = Site::where('slug', self::SLUG)->first();

        if (! $site) {
            $this->command?->warn('Site "' . self::SLUG . '" not found — nothing seeded.');

            return;
        }

        DB::transaction(function () use ($site): void {
            // Delete-then-refill, scoped to this ONE site.
            NavItem::where('site_id', $site->id)->delete();

            foreach (self::HEADER as $position => $item) {
                foreach ([NavItem::LOCATION_HEADER, NavItem::LOCATION_FOOTER] as $location) {
                    NavItem::create([
                        'site_id'          => $site->id,
                        'location'         => $location,
                        'label'            => $item['label'],
                        'url'              => $item['url'],
                        'position'         => $position,
                        'active'           => true,
                        'opens_in_new_tab' => false,
                    ]);
                }
            }

            // The forum page's own row. Created with every default, then turned
            // ON — winpalack is the site this feature was built for. The column
            // default stays FALSE so no other site is switched on by existing.
            $forum = $site->forumOrDefault();
            $forum->update(['enabled' => true]);
        });

        SiteCache::flushSite($site->id);

        $this->command?->info('Seeded ' . (count(self::HEADER) * 2) . ' nav items and enabled the forum for ' . self::SLUG . '.');
    }
}
