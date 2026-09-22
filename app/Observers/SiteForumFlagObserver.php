<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Redirect;
use App\Models\Site;

/**
 * Stops the Forum → Reviews redirect from shadowing the community forum.
 *
 * /forum was 301'd to /reviews by the rename. The new forum wants that exact
 * path back, and a redirect wins before routing does — so with both in place the
 * forum index would be unreachable and nothing in the codebase would explain
 * why.
 *
 * Rather than trusting an operator to remember, the two are made mutually
 * exclusive here: switching the forum on deactivates the redirect, switching it
 * off restores it. The row is never deleted, so the redirect's hit count and
 * the operator's ability to see it in the admin both survive.
 *
 * Only `active` is touched. If an operator has deliberately repointed or removed
 * that redirect, nothing here recreates it.
 */
class SiteForumFlagObserver
{
    private const SOURCE = '/forum';

    private const DESTINATION = '/reviews';

    /**
     * Give a NEW site the same redirect the migration gave the existing ones.
     *
     * Without this, the rename only covers sites that existed when the migration
     * ran: every domain registered afterwards inherits the front-end template
     * with its dead /forum path and no 301 to /reviews. The migration is a
     * one-off backfill; this is the rule.
     *
     * Found by a test — the suite creates its site after migrating, which is
     * exactly the shape of the production gap.
     */
    public function created(Site $site): void
    {
        Redirect::query()->updateOrCreate(
            ['site_id' => $site->id, 'source_path' => self::SOURCE],
            [
                'destination_path' => self::DESTINATION,
                'status_code'      => 301,
                // A site somehow created with the forum already on must not
                // redirect away from it.
                'active'           => ! (bool) $site->forum_enabled,
            ],
        );
    }

    public function updated(Site $site): void
    {
        if (! $site->wasChanged('forum_enabled')) {
            return;
        }

        Redirect::query()
            ->where('site_id', $site->id)
            ->where('source_path', self::SOURCE)
            ->where('destination_path', self::DESTINATION)
            // A live forum means the redirect must be off, and vice versa.
            ->update(['active' => ! (bool) $site->forum_enabled]);
    }
}
