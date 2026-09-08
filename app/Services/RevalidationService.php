<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\SiteRevalidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RevalidationService
{
    /**
     * Ping every affected Next.js site so it rebuilds stale static pages.
     *
     * WHAT CHANGED IN PHASE 0: the outcome of each attempt is now recorded. The
     * control flow is deliberately identical — still synchronous, still a 5s
     * timeout, still no rethrow, so a site that is down can never break an admin
     * write. The only addition is that the result stops being invisible.
     *
     * A 4xx/5xx RESPONSE IS NOW A FAILURE. Previously only a thrown exception
     * counted, and `Http::post()` does not throw on an error status — so a wrong
     * `REVALIDATE_SECRET` returned 401, satisfied this method, and was neither
     * logged nor surfaced. Every save looked fine while nothing on the site ever
     * updated. That is the single failure this phase exists to make visible.
     *
     * @param string[] $tags     Cache tags to invalidate (e.g. ['casinos'])
     * @param int[]    $siteIds  IDs of sites whose pages may be stale
     */
    public function revalidate(array $tags, array $siteIds, string $triggeredBy = SiteRevalidation::TRIGGER_OBSERVER): void
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

            $startedAt = microtime(true);
            $status = SiteRevalidation::STATUS_FAILED;
            $httpStatus = null;
            $error = null;

            try {
                $response = Http::withHeaders(['x-revalidate-secret' => config('services.revalidation.secret')])
                    ->timeout(5)
                    ->post($site->revalidation_url, ['tags' => $siteTags]);

                $httpStatus = $response->status();

                if ($response->successful()) {
                    $status = SiteRevalidation::STATUS_SUCCESS;
                } else {
                    // The body usually names the reason ({"ok":false}) and is
                    // short; truncated because a misconfigured URL can return a
                    // whole HTML error page.
                    $error = 'HTTP ' . $httpStatus . ': ' . mb_strimwidth(trim($response->body()), 0, 300, '…');
                }
            } catch (Throwable $e) {
                // Revalidation failure must never break an admin write.
                $error = $e->getMessage();

                Log::warning('Revalidation ping failed', [
                    'site_id' => $site->id,
                    'url'     => $site->revalidation_url,
                    'error'   => $error,
                ]);
            }

            $this->record($site, $siteTags, $status, $httpStatus, $error, $startedAt, $triggeredBy);
        }
    }

    /**
     * Persist the outcome.
     *
     * Wrapped in its own try/catch: this method exists to report failure, and it
     * must not become a new way for an admin save to fail. If the recording
     * itself breaks, the revalidation still happened and the log still has it.
     *
     * @param string[] $tags
     */
    private function record(
        Site $site,
        array $tags,
        string $status,
        ?int $httpStatus,
        ?string $error,
        float $startedAt,
        string $triggeredBy,
    ): void {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        try {
            SiteRevalidation::create([
                'site_id'      => $site->id,
                'tags'         => $tags,
                'status'       => $status,
                'http_status'  => $httpStatus,
                'error'        => $error === null ? null : mb_strimwidth($error, 0, 1000, '…'),
                'duration_ms'  => $durationMs,
                'triggered_by' => $triggeredBy,
            ]);

            // Denormalised onto the site so the admin LIST needs no aggregate.
            // A direct UPDATE, not a model save: `updated_at` on a site means
            // "an admin edited this site", and a background ping is not that.
            DB::table('sites')->where('id', $site->id)->update([
                'last_revalidated_at'      => now(),
                'last_revalidation_status' => $status,
                // Cleared on success, so the badge reflects the CURRENT state
                // rather than the last time anything ever went wrong.
                'last_revalidation_error'  => $status === SiteRevalidation::STATUS_SUCCESS ? null : $error,
            ]);
        } catch (Throwable $e) {
            Log::warning('Could not record revalidation outcome', [
                'site_id' => $site->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
