<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\VerificationPromotionEmail;
use App\Models\VerificationPromotionOverride;

/**
 * Per-site image + link overrides of the post-verification promotion.
 *
 * The screen edits SITES, not rows: the listing has an entry for every site and
 * a save is an upsert. A row whose fields are all blank is "nothing set", which
 * {@see VerificationPromotionOverride::overrides()} and the send path both read
 * as the defaults.
 */
final class VerificationPromotionOverrideService
{
    /**
     * One entry per registered site, including sites with nothing set, so the
     * admin screen can render the full list without inventing placeholders.
     *
     * @return array{footer_link_labels: list<string>, data: list<array<string, mixed>>}
     */
    public function listing(): array
    {
        $rows = VerificationPromotionOverride::query()
            ->get()
            ->keyBy('site_id');

        $sites = Site::query()->orderBy('name')->get(['id', 'name', 'slug', 'domain']);

        // The template's own footer links, label-only. The screen renders one
        // URL input PER default link, captioned with its label, so an operator
        // can see they are re-pointing "Privacy Policy" and not renaming it.
        $labels = collect(VerificationPromotionEmail::query()->first()?->footer_links ?? [])
            ->map(static fn ($l): string => (string) ($l['label'] ?? ''))
            ->all();

        return [
            'footer_link_labels' => $labels,
            'data' => $sites->map(static function (Site $site) use ($rows): array {
                /** @var VerificationPromotionOverride|null $row */
                $row = $rows->get($site->id);

                return [
                    'site_id' => (int) $site->id,
                    'site_name' => $site->name,
                    'site_slug' => $site->slug,
                    'domain' => $site->domain,
                    // Whether this site currently changes anything at all — the
                    // screen shows it as a badge, and it is the honest answer to
                    // "is this site still on the defaults?".
                    'active' => $row !== null && $row->overrides() !== [],
                    'hero_image_url' => $row?->hero_image_url,
                    'hero_url' => $row?->hero_url,
                    'top_button_url' => $row?->top_button_url,
                    'cta_button_url' => $row?->cta_button_url,
                    'email_preferences_url' => $row?->email_preferences_url,
                    'footer_link_urls' => $row?->footer_link_urls ?? [],
                ];
            })->all(),
        ];
    }

    /**
     * Upsert one site's overrides from validated input.
     *
     * @param  array<string, mixed>  $data  Validated input; absent keys are left untouched.
     * @param  bool  $footerLinksSent  Whether the caller actually sent `footer_link_urls`.
     */
    public function save(Site $site, array $data, bool $footerLinksSent): VerificationPromotionOverride
    {
        /*
         * Only when the caller actually sent the key.
         *
         * Every other field follows validated(), which omits absent keys and
         * so leaves them untouched. Defaulting this one to [] instead made a
         * partial update silently wipe the footer targets — inconsistent with
         * the rest of the payload and a quiet way to lose settings.
         *
         * Positional, so gaps are preserved rather than compacted: entry 2
         * must stay entry 2 even when entry 1 is blank, or clearing one link
         * would silently re-point the next one.
         */
        if ($footerLinksSent) {
            $data['footer_link_urls'] = array_map(
                static fn ($u): ?string => trim((string) $u) === '' ? null : trim((string) $u),
                $data['footer_link_urls'] ?? [],
            );
        }

        return VerificationPromotionOverride::query()->updateOrCreate(
            ['site_id' => $site->id],
            $data,
        );
    }
}
