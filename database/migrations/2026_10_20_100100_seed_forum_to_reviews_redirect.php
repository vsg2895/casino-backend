<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 301 for the Forum → Reviews rename.
 *
 * A ROW, not a rule in next.config.ts, because `proxy.ts` already resolves
 * admin-managed redirects per site and docs/admin-first.md asks for exactly
 * this: a URL change an operator can see and undo without a deploy.
 *
 * ── The collision, and why this row must be deletable ───────────────────────
 *
 * /forum is being handed to the NEW community forum. A permanent, un-removable
 * redirect would shadow that route forever: the request would 301 to /reviews
 * before Next ever matched the forum index. So the rule is:
 *
 *     the redirect is active for exactly as long as the site has no forum.
 *
 * That is not left to anybody's memory. `SiteForumFlagObserver` deactivates
 * this row the moment `sites.forum_enabled` flips true, and reactivates it if
 * the forum is switched back off. A redirect shadowing a live route is a bug,
 * not a configuration choice, so the app refuses to hold both at once.
 *
 * Seeded for EVERY site rather than just winpalack. The rename is a URL change
 * in the shared front-end template; any site that adopts it inherits the same
 * dead /forum path, and a 301 that never matches costs nothing.
 */
return new class extends Migration
{
    private const SOURCE = '/forum';

    private const DESTINATION = '/reviews';

    public function up(): void
    {
        $now = now();

        $rows = DB::table('sites')
            ->select('id', 'forum_enabled')
            ->get()
            ->map(static fn (object $site): array => [
                'site_id'          => $site->id,
                'source_path'      => self::SOURCE,
                'destination_path' => self::DESTINATION,
                'status_code'      => 301,
                // A site that already has the new forum must not redirect away
                // from it. Nothing satisfies this today — the column was added
                // one migration ago and defaults to false — but the condition is
                // written rather than assumed, so re-running this against a
                // populated database cannot break a live forum.
                'active'           => ! (bool) ($site->forum_enabled ?? false),
                'created_at'       => $now,
                'updated_at'       => $now,
            ])
            ->all();

        if ($rows === []) {
            return;
        }

        // Idempotent: the unique key is (site_id, source_path), so a re-run
        // refreshes the destination instead of failing. `active` is deliberately
        // NOT in the update list — an operator who turned this off, or the
        // observer that turned it off, must not have that undone by a redeploy.
        DB::table('redirects')->upsert($rows, ['site_id', 'source_path'], ['destination_path', 'status_code', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('redirects')
            ->where('source_path', self::SOURCE)
            ->where('destination_path', self::DESTINATION)
            ->delete();
    }
};
