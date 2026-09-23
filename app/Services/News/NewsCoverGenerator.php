<?php

declare(strict_types=1);

namespace App\Services\News;

use App\Support\Media\SvgSanitizer;
use Illuminate\Support\Facades\Storage;

/**
 * Draws a cover for every scraped news item.
 *
 * ── Why drawn rather than taken ─────────────────────────────────────────────
 *
 * The feeds do carry photographs, and using them was considered and rejected.
 * Facts are not copyrightable, which is what the whole ingestion pipeline rests
 * on; a news photograph is the opposite — a separately owned creative work,
 * usually licensed from an agency, and the most actively enforced category of
 * content on the web. One configured source credits Associated Press in its
 * items, and its captions name individuals, which adds personality rights on
 * top of copyright.
 *
 * Coverage settled it even before the rights did. Of the three enabled sources,
 * exactly ONE exposes a usable <media:content>; the other two carry images only
 * inside <content:encoded>, the copyrighted body {@see NewsFeedParser} is built
 * never to read. A feed where two cards in three are text-only reads as broken.
 *
 * So the artwork is generated here: no licence, no attribution, no network
 * dependency, no takedown risk, and every single item gets one.
 *
 * ── What the picture says ───────────────────────────────────────────────────
 *
 * Not decoration for its own sake. The MARK is chosen from the story's own
 * words, so a licensing story and a payments story are visibly different at a
 * glance in the feed, and the HUE is derived from the slug so two stories on
 * the same topic are still distinguishable. Both are deterministic: the same
 * article always produces the same cover, so a re-run overwrites one file
 * rather than accumulating orphans.
 */
class NewsCoverGenerator
{
    /** Where the files land on the `public` disk, beside the other news art. */
    private const string DIRECTORY = 'news';

    /**
     * Topic marks, in priority order.
     *
     * First match wins, so the more specific patterns come first — a story
     * about a licence being revoked over player-protection failings is filed
     * under licensing, which is what its headline leads with.
     *
     * @var array<string, string>
     */
    private const array TOPICS = [
        'responsible' => 'responsib|problem gambl|self-exclu|harm|addict|player protect|safer gambl|affordab|welfare',
        'licensing'   => 'licen[cs]|regulat|commission|ukgc|mga|compliance|enforce|fine[sd]?\b|illegal|ban\b|block|court|legal|law\b|tax',
        'payments'    => 'payment|withdraw|deposit|payout|bank|crypto|stablecoin|wallet|transaction|kyc|revenue|profit|financ',
    ];

    /**
     * Write the cover and return its stored path.
     *
     * The path is relative to the public disk, which is the form
     * `hero_image_path` takes everywhere else and what the sites' own
     * resolveImageUrl() expects.
     */
    public function generate(string $slug, string $headline, string $teaser = ''): ?string
    {
        $path = self::DIRECTORY . '/' . $slug . '.svg';

        $svg = SvgSanitizer::clean($this->draw($slug, $this->topicFor($headline . ' ' . $teaser)));

        if ($svg === null) {
            // Refuses to write rather than putting unverified markup on a disk
            // the public reads from. The article still publishes; the card is
            // simply text-only, which every listing already handles.
            return null;
        }

        Storage::disk('public')->put($path, $svg);

        return $path;
    }

    /** Which mark this story gets. */
    private function topicFor(string $text): string
    {
        $text = mb_strtolower($text);

        foreach (self::TOPICS as $topic => $pattern) {
            if (preg_match('/' . $pattern . '/u', $text) === 1) {
                return $topic;
            }
        }

        return 'general';
    }

    /**
     * The artwork.
     *
     * 1600x900 to match the 16:9 the listing crops from, and built entirely
     * from shapes — no text, because a headline rendered into an image is
     * invisible to search engines and to anyone using a screen reader, and the
     * real headline sits directly beneath the card anyway.
     */
    private function draw(string $slug, string $topic): string
    {
        // Deterministic, and confined to the emerald -> teal -> cyan arc this
        // site is built on, so a feed of twenty covers reads as one family
        // rather than a colour wheel.
        $hue = 150 + (int) (hexdec(substr(md5($slug), 0, 4)) % 46);
        $id  = substr(md5($slug), 0, 8);

        $mark = $this->mark($topic);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1600 900" role="img" aria-label="">
          <defs>
            <linearGradient id="g{$id}" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="hsl({$hue} 58% 30%)"/>
              <stop offset="100%" stop-color="hsl({$hue} 62% 50%)"/>
            </linearGradient>
          </defs>
          <rect width="1600" height="900" fill="url(#g{$id})"/>
          <g fill="none" stroke="#ffffff" stroke-opacity="0.13" stroke-width="2">
            <circle cx="1290" cy="220" r="200"/>
            <circle cx="1290" cy="220" r="320"/>
            <circle cx="250" cy="760" r="150"/>
          </g>
          <g transform="translate(800 450)" fill="none" stroke="#ffffff" stroke-opacity="0.92"
             stroke-width="14" stroke-linecap="round" stroke-linejoin="round">
            {$mark}
          </g>
        </svg>
        SVG;
    }

    /**
     * The glyph, centred on the origin.
     *
     * Each one is the plainest possible statement of its topic: a shield for
     * licensing, a card for payments, an open hand for responsible play, and a
     * folded page for everything else.
     */
    private function mark(string $topic): string
    {
        return match ($topic) {
            'licensing' => '<path d="M0 -150 L130 -95 v110 q0 130 -130 185 q-130 -55 -130 -185 v-110 z"/>'
                . '<path d="M-58 5 L-16 48 L62 -40"/>',

            'payments' => '<rect x="-160" y="-105" width="320" height="210" rx="26"/>'
                . '<path d="M-160 -35 h320"/>'
                . '<path d="M-110 50 h90"/>',

            'responsible' => '<path d="M-108 60 v-150 a26 26 0 0 1 52 0 v60"/>'
                . '<path d="M-56 -30 v-96 a26 26 0 0 1 52 0 v96"/>'
                . '<path d="M-4 -30 v-80 a26 26 0 0 1 52 0 v80"/>'
                . '<path d="M48 -30 v-46 a26 26 0 0 1 52 0 v120 q0 106 -104 106 q-104 0 -104 -106"/>',

            default => '<path d="M-130 -150 h190 l70 70 v230 a20 20 0 0 1 -20 20 h-240 a20 20 0 0 1 -20 -20 v-280 a20 20 0 0 1 20 -20 z"/>'
                . '<path d="M60 -150 v70 h70"/>'
                . '<path d="M-78 -10 h150 M-78 60 h150"/>',
        };
    }
}
