<?php

declare(strict_types=1);

namespace App\Services\News;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns one RSS document into FACTS.
 *
 * ── The copyright boundary, enforced here ───────────────────────────────────
 *
 * Every feed this project reads also ships `content:encoded` — the publisher's
 * full article body, 1,500 to 10,600 characters, sometimes with their images.
 * That is their copyrighted prose, and it is one field name away from being
 * read by accident.
 *
 * So this class NEVER touches it. What it takes is the set a fact-based rewrite
 * needs and nothing more: headline, teaser, publish date, categories, canonical
 * URL, and the numeric id that identifies the item. {@see self::EXCLUDED}
 * records the fields that are deliberately not read, and a test asserts the
 * parsed output cannot contain the body.
 *
 * ── Why this handles every source ───────────────────────────────────────────
 *
 * All six configured feeds are WordPress, with an identical element set. One
 * parser, no per-site selectors, and nothing to break when a source redesigns
 * its pages — a feed's shape is a contract in a way a page's markup is not.
 */
class NewsFeedParser
{
    /**
     * Fields present in the source that are deliberately NOT parsed.
     *
     * Documented as data rather than prose so the intent is greppable: if one
     * of these ever appears in an item array, something has gone wrong.
     *
     * @var list<string>
     */
    public const array EXCLUDED = ['content:encoded', 'media:content', 'media:thumbnail', 'enclosure'];

    /** Longest slug this will generate, before trimming to a word boundary. */
    private const int SLUG_MAX = 80;

    /**
     * Parse a feed document into fact rows.
     *
     * Per-item try/catch: a single malformed entry must not cost the other
     * ninety-nine. An item missing anything essential is skipped with a reason
     * rather than written as a half-empty row.
     *
     * @return array{items: list<array<string, mixed>>, skipped: list<string>}
     */
    public function parse(string $xml, string $sourceKey, string $sourceName, int $minTeaser = 40): array
    {
        $items = [];
        $skipped = [];

        $previous = libxml_use_internal_errors(true);

        try {
            $doc = simplexml_load_string($xml);
        } catch (Throwable) {
            $doc = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($doc === false || ! isset($doc->channel->item)) {
            // A feed that stops being a feed is reported to the caller, never
            // treated as "no news today" — those two look identical in a log
            // and mean completely different things.
            return ['items' => [], 'skipped' => ['the document is not an RSS feed, or carries no <item> elements']];
        }

        foreach ($doc->channel->item as $node) {
            try {
                $item = $this->itemFrom($node, $sourceKey, $sourceName, $minTeaser);

                if (is_string($item)) {
                    $skipped[] = $item;

                    continue;
                }

                $items[] = $item;
            } catch (Throwable $e) {
                $skipped[] = 'unreadable item: ' . $e->getMessage();
            }
        }

        return ['items' => $items, 'skipped' => $skipped];
    }

    /**
     * One item, or a string explaining why it was skipped.
     *
     * @return array<string, mixed>|string
     */
    private function itemFrom(\SimpleXMLElement $node, string $sourceKey, string $sourceName, int $minTeaser): array|string
    {
        $headline = $this->text($node->title);
        $url      = $this->text($node->link);

        if ($headline === '' || $url === '') {
            return 'item with no title or no link';
        }

        // The numeric WordPress post id out of the guid. Stable for the life of
        // the post and identical on every re-fetch, which is what makes it the
        // identity of the item rather than its wording.
        $sourceId = $this->sourceIdFrom($this->text($node->guid), $url);

        if ($sourceId === null) {
            return "no usable id in the guid for: {$headline}";
        }

        $teaser = $this->teaserFrom($this->text($node->description));

        if (mb_strlen($teaser) < $minTeaser) {
            // Too thin to rewrite from without inventing the difference.
            return "teaser too short (" . mb_strlen($teaser) . " chars) for: {$headline}";
        }

        $publishedAt = $this->dateFrom($this->text($node->pubDate));

        if ($publishedAt === null) {
            return "unreadable pubDate for: {$headline}";
        }

        return [
            'source_key'   => $sourceKey,
            'source_name'  => $sourceName,
            'source_id'    => $sourceId,
            // The dedup key: namespaced by source, so two publishers reusing
            // the same post id cannot collide.
            'source_ref'   => $sourceKey . ':' . $sourceId,
            'url'          => $url,
            'headline'     => $headline,
            'teaser'       => $teaser,
            'categories'   => $this->categoriesFrom($node),
            'published_at' => $publishedAt,
            // A PROVISIONAL slug. Dedup lives on `source_ref`, so this only
            // has to be unique and readable until NewsRewriteService replaces
            // it with one built from the original title it writes.
            'slug'         => $this->slugFor($headline),
        ];
    }

    /**
     * The item's id.
     *
     * `?p=<id>` is the WordPress guid form every configured source uses. The
     * path fallback exists so a source that changes its permalink style
     * degrades to "identified by URL" instead of vanishing from the run.
     */
    private function sourceIdFrom(string $guid, string $url): ?string
    {
        foreach ([$guid, $url] as $candidate) {
            if ($candidate !== '' && preg_match('/[?&]p=(\d+)/', $candidate, $m) === 1) {
                return $m[1];
            }
        }

        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === '' ? null : Str::limit(Str::slug($path), 150, '');
    }

    /**
     * The teaser, as plain text.
     *
     * WordPress wraps it in <p> and appends a "The post X appeared first on Y"
     * line; both are presentation, and the second is the publisher's own
     * boilerplate rather than anything about the story.
     */
    private function teaserFrom(string $html): string
    {
        $text = preg_replace('/<p>\s*The post\s.*?appeared first on.*?<\/p>/is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /** @return list<string> */
    private function categoriesFrom(\SimpleXMLElement $node): array
    {
        $out = [];

        foreach ($node->category ?? [] as $category) {
            $value = $this->text($category);

            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    private function dateFrom(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The slug, trimmed at a word boundary rather than mid-word.
     *
     * Deterministic: the same headline always yields the same slug, which is
     * what lets a re-run recognise an item it has already taken.
     */
    private function slugFor(string $headline): string
    {
        $slug = Str::slug($headline);

        if (strlen($slug) <= self::SLUG_MAX) {
            return $slug;
        }

        $slug = substr($slug, 0, self::SLUG_MAX);
        $cut  = strrpos($slug, '-');

        // Only trim back to a boundary if that still leaves a readable slug;
        // one enormous word should be cut short rather than emptied.
        return ($cut !== false && $cut > 40) ? substr($slug, 0, $cut) : rtrim($slug, '-');
    }

    private function text(?\SimpleXMLElement $node): string
    {
        return $node === null ? '' : trim((string) $node);
    }
}
