<?php

declare(strict_types=1);

/**
 * News ingestion — sources, and the switches that keep it deliberate.
 *
 * WHY RSS AND NOT A SCRAPE. Every source below publishes a feed, which is the
 * channel a publisher offers to machines on purpose. It also means one parser
 * for every source instead of per-site CSS selectors that break on a redesign,
 * and no HTML-parsing dependency at all — simplexml is in core.
 *
 * casino.guru is deliberately ABSENT. It sits behind a Cloudflare managed
 * challenge that returns 403 to every HTTP client (verified on /news and
 * /sitemap_index.xml), and reaching it would mean impersonating a browser to
 * defeat an access control its operator installed. Their robots.txt does not
 * disallow /news, so the two disagree — that is a reason to ask them for an
 * allowlist, not a licence to proceed.
 */
return [

    /** The site scraped news is filed under. One site; news is per-domain. */
    'site_slug' => env('NEWS_SITE_SLUG', 'winpalack'),

    /**
     * Identifies us to every source, with a contact route.
     *
     * A real, attributable agent string is the difference between a polite
     * client and an anonymous one, and it is what lets a publisher block or
     * allow us deliberately instead of guessing.
     */
    'user_agent' => env('NEWS_USER_AGENT', 'winpalack-newsbot/1.0 (+https://winpalack.com; editorial research)'),

    /** Seconds before a feed request is abandoned. */
    'timeout' => 20,

    /** Seconds between requests. Courtesy, not a rate limit anyone imposed. */
    'delay_seconds' => 2,

    /**
     * Shortest teaser worth rewriting.
     *
     * The rewriter is given the headline and the teaser and nothing else, so an
     * item with almost no teaser produces either a thin article or an invented
     * one. Skipping is the honest outcome; the surveyed feeds run 120-560
     * characters, so this excludes almost nothing.
     */
    'min_teaser_chars' => 40,

    /**
     * OFF by default. The schedule entry in routes/console.php reads this, so
     * hourly ingestion starts when an operator turns it on, never because a
     * deploy shipped the code.
     */
    'scheduled' => (bool) env('NEWS_SCRAPE_SCHEDULED', false),

    /**
     * Feeds, in order of editorial usefulness.
     *
     * Every one was probed: HTTP 200 to a plain identifying User-Agent,
     * WordPress RSS with an identical field set, and a `guid` of the form
     * `?p=<id>` giving a stable numeric id - 210 of 210 parsed uniquely across
     * the survey.
     *
     * ROBOTS IS CHECKED AT RUN TIME, not here, and that is not belt-and-braces:
     * an early version of this list had sbcnews enabled on the strength of a
     * hand-written survey that misread its robots.txt. The runtime check caught
     * it and refused the fetch. A source's permission can change the day after
     * anyone reads it, so the list records intent and the check decides.
     *
     * `relevance` records the share of items whose headline or categories
     * matched this site's own taxonomy (licensing, payments, responsible play)
     * at the time of the survey. It is a note for whoever tunes this list, not
     * a value the code reads.
     */
    'sources' => [
        [
            'key'       => 'igamingbusiness',
            'name'      => 'iGaming Business',
            'url'       => 'https://igamingbusiness.com/feed/',
            'enabled'   => true,
            'relevance' => '50% - deepest archive (100 items), regulation-heavy',
        ],
        [
            'key'       => 'casinonewsdaily',
            'name'      => 'Casino News Daily',
            'url'       => 'https://www.casinonewsdaily.com/feed/',
            'enabled'   => true,
            'relevance' => '50% - licensing and enforcement, almost no sports noise',
        ],
        [
            'key'       => 'sbcnews',
            'name'      => 'SBC News',
            'url'       => 'https://sbcnews.co.uk/feed/',
            // OFF, and it must stay off: their robots.txt carries
            // `Disallow: /feed/` in the wildcard group and allows the feed only
            // for `User-agent: NewsNow`. The runtime check refuses the fetch
            // anyway, so enabling this would only produce a failed source in
            // every run. Ask them for an allowlist if the content is wanted.
            'enabled'   => false,
            'relevance' => '40% - UK regulation, but robots.txt disallows /feed/ for us',
        ],

        [
            'key'       => 'gamblingnews',
            'name'      => 'Gambling News',
            'url'       => 'https://www.gamblingnews.com/feed/',
            // Enabled in sbcnews's place: robots permits it, and it keeps the
            // intake at three sources.
            'enabled'   => true,
            'relevance' => '30% - mixed consumer and industry',
        ],

        /*
         * Live and usable, left OFF so the default run stays on-topic. Flip
         * `enabled` to widen the intake.
         */
        [
            'key'       => 'europeangaming',
            'name'      => 'European Gaming',
            'url'       => 'https://europeangaming.eu/portal/feed/',
            'enabled'   => false,
            'relevance' => '40% - EU regulation mixed with supplier announcements',
        ],
        [
            'key'       => 'casino.org',
            'name'      => 'Casino.org',
            'url'       => 'https://www.casino.org/news/feed/',
            'enabled'   => false,
            'relevance' => '22% - US corporate news, heavy NFL content',
        ],

        /*
         * Surveyed and REJECTED, recorded so nobody re-adds them:
         *   calvinayre.com     feed serves 200 but its newest item is from
         *                      2021-02-24 and the lead headline is "Farewell
         *                      sweet CalvinAyre.com". The site shut down.
         *   casinobeats.com    newest item 19 days old; the feed has stopped.
         *   gamblinginsider    403, same Cloudflare posture as casino.guru.
         *   cardplayer, pokernews, yogonet, gamingintelligence - serve HTML,
         *                      not XML, at their advertised feed URLs.
         */
    ],

    'rewrite' => [
        /**
         * Cheap by default. This runs per article and the job is constrained
         * rewriting of supplied facts, not open-ended reasoning.
         */
        'model'      => env('NEWS_REWRITE_MODEL', 'claude-haiku-4-5-20251001'),
        'max_tokens' => 2000,
        'timeout'    => 60,
        /** Articles per `news:rewrite` run unless --limit says otherwise. */
        'batch'      => 10,
    ],
];
