<?php

declare(strict_types=1);

namespace App\Support\Forum;

use App\Models\Site;

/**
 * The name the editorial team publishes under.
 *
 * ── Why it is derived and not a constant ────────────────────────────────────
 *
 * One admin account writes for six domains. "Winpalack Team" printed on another
 * brand's forum would be wrong, and a per-site constant would be a list somebody
 * has to remember to extend the next time a domain is registered.
 *
 * ── Why it is here and not in a model ───────────────────────────────────────
 *
 * Two resources need the identical answer — ForumArticleResource for a
 * discussion and ForumPostResource for a reply — and they must never drift.
 * A thread whose opener is signed "Winpalack Team" and whose reply is signed
 * something else reads as two different authors.
 *
 * The public request already has its site bound by VerifySiteAccess, so this
 * normally costs no query; the relation is the fallback for anything resolved
 * outside a site-scoped request, such as a seeder or a console command.
 */
final class ForumTeamName
{
    /** Used when no site can be resolved at all — never expected in practice. */
    private const string FALLBACK = 'Editorial Team';

    public static function for(?Site $site = null): string
    {
        $site ??= app()->bound('current_site') ? app('current_site') : null;

        $name = trim((string) ($site->name ?? ''));

        return $name === '' ? self::FALLBACK : $name . ' Team';
    }
}
