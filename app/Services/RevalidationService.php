<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RevalidationService
{
    /**
     * Ping every affected Next.js site so it rebuilds stale static pages.
     *
     * @param string[] $tags     Cache tags to invalidate (e.g. ['casinos'])
     * @param int[]    $siteIds  IDs of sites whose pages may be stale
     */
    public function revalidate(array $tags, array $siteIds): void
    {
        if (empty($siteIds) || empty($tags)) {
            return;
        }

        $sites = Site::whereIn('id', $siteIds)->where('active', true)->get();

        foreach ($sites as $site) {
            if (! $site->revalidation_url) {
                continue;
            }

            // Always include the site's own tag — every public fetch is tagged with it,
            // so this refreshes ALL of that site's pages (list, detail, categories, offers).
            $siteTags = array_values(array_unique([...$tags, 'site:' . $site->slug]));

            try {
                Http::withHeaders(['x-revalidate-secret' => config('services.revalidation.secret')])
                    ->timeout(5)
                    ->post($site->revalidation_url, ['tags' => $siteTags]);
            } catch (\Throwable $e) {
                // Revalidation failure must never break an admin write
                Log::warning('Revalidation ping failed', [
                    'site_id' => $site->id,
                    'url'     => $site->revalidation_url,
                    'error'   => $e->getMessage(),
                ]);
            }
        }
    }
}
