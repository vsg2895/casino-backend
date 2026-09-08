<?php

declare(strict_types=1);

namespace App\Support\Seo;

use App\Models\SeoTemplate;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Turns a stored pattern plus a record into the finished strings a page uses.
 *
 * THE single place substitution happens. The front end receives resolved text
 * and never sees a token, so the six sites cannot drift into six slightly
 * different interpretations of `{{month}}`.
 *
 * Precedence, highest first:
 *
 *   1. the record's own `meta_title` / `meta_description` — an explicit override
 *      always wins, because someone typed it for this record specifically;
 *   2. the site's pattern for that entity type;
 *   3. null, and the front end falls back to the wording in its own code.
 *
 * Returning null at step 3 rather than inventing a string is what keeps this
 * feature invisible until a site adopts it.
 */
final class SeoResolver
{
    /** @var array<string, SeoTemplate|null> */
    private array $cache = [];

    /**
     * @param  array<string, string>  $tokens  Record-specific values, e.g. ['name' => 'BitStarz'].
     * @return array{title: string|null, description: string|null}
     */
    public function resolve(
        Site $site,
        string $entity,
        array $tokens,
        ?string $ownTitle,
        ?string $ownDescription,
    ): array {
        $template = $this->template($site, $entity);

        return [
            'title'       => $this->pick($ownTitle, $template?->title_pattern, $tokens, $site),
            'description' => $this->pick($ownDescription, $template?->description_pattern, $tokens, $site),
        ];
    }

    /**
     * Render one pattern without a record, for the admin's live preview.
     *
     * Same substitution as a real page, so what the preview shows is what ships.
     *
     * @param  array<string, string>  $tokens
     */
    public function preview(Site $site, string $pattern, array $tokens): string
    {
        return $this->substitute($pattern, $tokens, $site);
    }

    /** @param array<string, string> $tokens */
    private function pick(?string $own, ?string $pattern, array $tokens, Site $site): ?string
    {
        $own = trim((string) $own);

        if ($own !== '') {
            return $own;
        }

        $pattern = trim((string) $pattern);

        return $pattern === '' ? null : $this->substitute($pattern, $tokens, $site);
    }

    /**
     * Replace the tokens a pattern may contain.
     *
     * Unknown tokens are left ALONE rather than blanked. A pattern containing
     * `{{rating}}` is an editor asking for something that does not exist, and
     * seeing the token survive in the preview is how they find that out —
     * silently deleting it would look like the pattern worked.
     *
     * @param  array<string, string>  $tokens
     */
    private function substitute(string $pattern, array $tokens, Site $site): string
    {
        $now = Carbon::now();

        $values = [
            'site_name' => (string) $site->name,
            'year'      => (string) $now->year,
            'month'     => $now->format('F'),
            ...$tokens,
        ];

        foreach ($values as $key => $value) {
            $pattern = str_replace(
                ['{{' . $key . '}}', '{{ ' . $key . ' }}'],
                $value,
                $pattern,
            );
        }

        // Collapse the spacing a substituted-empty token can leave behind.
        return trim((string) preg_replace('/\s{2,}/', ' ', $pattern));
    }

    /** Memoised per request: a listing page resolves this once, not once per row. */
    private function template(Site $site, string $entity): ?SeoTemplate
    {
        $key = $site->id . ':' . $entity;

        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = SeoTemplate::query()
                ->where('site_id', $site->id)
                ->where('entity', $entity)
                ->first();
        }

        return $this->cache[$key];
    }
}
