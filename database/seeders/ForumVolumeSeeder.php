<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ForumArticle;
use App\Models\ForumPost;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Acceptance-criteria volume: 50,000 posts across 500 articles in 20 categories.
 *
 * This exists to MEASURE the schema, not to populate a site. It is guarded
 * against production and it writes with raw bulk inserts rather than Eloquent:
 *
 *  - 50,000 model events would fire 50,000 observer transactions, which would
 *    take minutes and would prove nothing about the query plans.
 *  - The counters are rebuilt afterwards by `forum:recount`, which is also a
 *    real test of that command against a table it has to chunk through.
 *
 * Every row is marked as sample data in its body text, and the member addresses
 * are @example.test — a reserved domain that cannot receive mail, so a stray
 * campaign against seeded rows can never reach a real inbox.
 *
 *   php artisan db:seed --class=ForumVolumeSeeder
 */
class ForumVolumeSeeder extends Seeder
{
    private const SECTIONS = 4;

    private const CATEGORIES = 20;

    private const ARTICLES = 500;

    private const POSTS = 50_000;

    private const MEMBERS = 2_000;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to run: this seeder writes 50,000 rows of sample data.');

            return;
        }

        $site = Site::query()->where('slug', 'winpalack')->first();

        if ($site === null) {
            $this->command?->error('No winpalack site — nothing to seed against.');

            return;
        }

        $admin = User::query()->value('id');
        $now = now();

        $this->command?->info('Seeding forum volume…');

        $memberIds = $this->members($site->id, $now);
        $this->command?->info('  members:    ' . count($memberIds));

        $categoryIds = $this->taxonomy($site->id, $now);
        $this->command?->info('  categories: ' . count($categoryIds));

        $articleIds = $this->articles($site->id, $admin, $categoryIds, $now);
        $this->command?->info('  articles:   ' . count($articleIds));

        $this->posts($site->id, $articleIds, $memberIds, $now);
        $this->command?->info('  posts:      ' . self::POSTS);

        $this->command?->info('Rebuilding counters…');
        $this->command?->call('forum:recount', ['--site' => $site->slug]);
    }

    /** @return list<int> */
    private function members(int $siteId, \DateTimeInterface $now): array
    {
        // One hash for every seeded member. Hashing 2,000 passwords costs about
        // a minute of bcrypt and buys nothing — none of these accounts is ever
        // logged into. The value is still a real hash, so nothing downstream has
        // to special-case it.
        $hash = Hash::make('seeded-account-not-for-login');
        $rows = [];

        for ($i = 1; $i <= self::MEMBERS; $i++) {
            $rows[] = [
                'site_id'              => $siteId,
                'display_name'         => "Sample Member {$i}",
                'slug'                 => "sample-member-{$i}",
                'email'                => "sample.member.{$i}@example.test",
                'email_verified_at'    => $now,
                'password'             => $hash,
                'trust_level'          => 0,
                'posts_count'          => 0,
                'approved_posts_count' => 0,
                'status'               => 'active',
                'last_seen_at'         => $now,
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('forum_users')->insert($chunk);
        }

        return DB::table('forum_users')
            ->where('site_id', $siteId)
            ->where('email', 'like', 'sample.member.%@example.test')
            ->pluck('id')
            ->all();
    }

    /** @return list<int> */
    private function taxonomy(int $siteId, \DateTimeInterface $now): array
    {
        $sectionNames = ['Gambling Section', 'Online Casinos', 'Payments & Withdrawals', 'Off Topic'];
        $sectionIds = [];

        foreach ($sectionNames as $i => $name) {
            $sectionIds[] = DB::table('forum_sections')->insertGetId([
                'site_id'    => $siteId,
                'name'       => $name,
                'slug'       => Str::slug($name),
                'position'   => $i,
                'active'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rows = [];

        for ($i = 1; $i <= self::CATEGORIES; $i++) {
            $rows[] = [
                'site_id'          => $siteId,
                'forum_section_id' => $sectionIds[$i % self::SECTIONS],
                'name'             => "Sample Board {$i}",
                'slug'             => "sample-board-{$i}",
                // No placeholder subline: a description renders under the
                // board's name on the public site, and "sample data" copy
                // there reads as a broken page rather than an empty field.
                'description'      => null,
                'position'         => $i,
                'active'           => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        DB::table('forum_categories')->insert($rows);

        return DB::table('forum_categories')
            ->where('site_id', $siteId)
            ->where('slug', 'like', 'sample-board-%')
            ->pluck('id')
            ->all();
    }

    /** @return list<int> */
    private function articles(int $siteId, ?int $adminId, array $categoryIds, \DateTimeInterface $now): array
    {
        $rows = [];
        $count = count($categoryIds);

        for ($i = 1; $i <= self::ARTICLES; $i++) {
            // Spread publication over two years so date-ordered queries have a
            // real distribution to walk rather than 500 identical timestamps.
            $published = (clone $now)->modify('-' . random_int(0, 730) . ' days');

            $rows[] = [
                'site_id'           => $siteId,
                'forum_category_id' => $categoryIds[$i % $count],
                'user_id'           => $adminId,
                'title'             => "Sample Discussion {$i}",
                'slug'              => "sample-discussion-{$i}",
                'excerpt'           => null,
                'body'              => "<p>Sample article body {$i}. Created by ForumVolumeSeeder.</p>",
                'status'            => 'published',
                // Roughly one in fifty pinned, so the category page's
                // "pinned first" ordering has something to order.
                'pinned'            => $i % 50 === 0,
                'locked'            => false,
                'published_at'      => $published,
                'posts_count'       => 0,
                'views_count'       => random_int(0, 40_000),
                'hot_score'         => 0,
                'created_at'        => $published,
                'updated_at'        => $published,
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('forum_articles')->insert($chunk);
        }

        return DB::table('forum_articles')
            ->where('site_id', $siteId)
            ->where('slug', 'like', 'sample-discussion-%')
            ->pluck('id')
            ->all();
    }

    private function posts(int $siteId, array $articleIds, array $memberIds, \DateTimeInterface $now): void
    {
        $articleCount = count($articleIds);
        $memberCount = count($memberIds);
        $rows = [];
        $written = 0;

        // Top-level posts are written first so that comments have real parents
        // to point at — and so `depth` and `parent_id` never disagree, which the
        // CHECK constraint would reject outright.
        $topLevelPerArticle = (int) floor(self::POSTS * 0.8 / $articleCount);

        for ($a = 0; $a < $articleCount; $a++) {
            for ($p = 0; $p < $topLevelPerArticle; $p++) {
                $created = (clone $now)->modify('-' . random_int(0, 700) . ' days');
                $rows[] = [
                    'site_id'          => $siteId,
                    'forum_article_id' => $articleIds[$a],
                    'parent_id'        => null,
                    'depth'            => 0,
                    'forum_user_id'    => $memberIds[($a * 7 + $p) % $memberCount],
                    'body'             => 'Sample post body. Created by ForumVolumeSeeder for performance measurement.',
                    // A realistic queue: most approved, a slice pending, a
                    // little rejected. A table of 100% approved rows would make
                    // the moderation-queue index look better than it is.
                    'status'           => $this->status($p),
                    'approved_at'      => $created,
                    'ip_address'       => DB::raw("INET6_ATON('203.0.113." . ($p % 254 + 1) . "')"),
                    'created_at'       => $created,
                    'updated_at'       => $created,
                ];
                $written++;

                if (count($rows) >= 1_000) {
                    $this->flush($rows);
                }
            }
        }

        $this->flush($rows);

        // Comments, attached to real top-level posts. One query per article
        // would be 500 round trips; instead each batch draws parents from a
        // window of ids that is known to exist.
        $parentIds = DB::table('forum_posts')
            ->where('site_id', $siteId)
            ->where('depth', 0)
            ->inRandomOrder()
            ->limit(5_000)
            ->pluck('id', 'id');

        $parents = DB::table('forum_posts')
            ->whereIn('id', $parentIds->keys())
            ->pluck('forum_article_id', 'id')
            ->all();

        $parentKeys = array_keys($parents);
        $parentCount = count($parentKeys);

        while ($written < self::POSTS && $parentCount > 0) {
            $parentId = $parentKeys[$written % $parentCount];
            $created = (clone $now)->modify('-' . random_int(0, 365) . ' days');

            $rows[] = [
                'site_id'          => $siteId,
                // A comment MUST sit on its parent's article — a mismatch would
                // put a reply in a thread its parent is not in.
                'forum_article_id' => $parents[$parentId],
                'parent_id'        => $parentId,
                'depth'            => 1,
                'forum_user_id'    => $memberIds[$written % $memberCount],
                'body'             => 'Sample comment body. Created by ForumVolumeSeeder.',
                'status'           => $this->status($written),
                'approved_at'      => $created,
                'ip_address'       => DB::raw("INET6_ATON('198.51.100." . ($written % 254 + 1) . "')"),
                'created_at'       => $created,
                'updated_at'       => $created,
            ];
            $written++;

            if (count($rows) >= 1_000) {
                $this->flush($rows);
            }
        }

        $this->flush($rows);
    }

    /** ~92% approved, ~5% pending, ~3% rejected or spam. */
    private function status(int $n): string
    {
        return match (true) {
            $n % 20 === 0 => ForumPost::STATUS_PENDING,
            $n % 33 === 0 => ForumPost::STATUS_REJECTED,
            $n % 97 === 0 => ForumPost::STATUS_SPAM,
            default       => ForumPost::STATUS_APPROVED,
        };
    }

    private function flush(array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('forum_posts')->insert($rows);
        $rows = [];
    }
}
