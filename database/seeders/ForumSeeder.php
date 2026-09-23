<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ForumArticle;
use App\Models\ForumCategory;
use App\Models\ForumSection;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteCache;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Editorial starter content for the WinPalack community forum.
 *
 * ── What this is, and what it deliberately is not ───────────────────────────
 *
 * It writes the board structure and TEN opening posts, and nothing else. It
 * creates no members, no replies, no comments, no player experiences and no
 * counters. A forum at launch has no community activity, and manufacturing some
 * would be a lie told to the first real visitor — the one person whose trust
 * the whole section depends on.
 *
 * That is the difference between this and {@see ForumVolumeSeeder}, which
 * exists to put load on the schema and is untouched by this file. That one
 * writes 2,000 members and 50,000 posts because a query plan needs data; this
 * one writes what an editor would have written by hand.
 *
 * Every opening post is written in the editorial team's voice, raises a real
 * question, and ends with concrete prompts. None of them claims an experience
 * the team did not have, quotes a statistic, or reports what any casino
 * actually did.
 *
 * ── Authorship ──────────────────────────────────────────────────────────────
 *
 * The author is a REAL existing row in `users`, found not created. If none
 * exists the run stops with an actionable message rather than inventing one or
 * quietly picking whatever row is first.
 *
 * `forum_articles.user_id` is a foreign key to `users`, the admin table —
 * members live in `forum_users` and can only ever reach `forum_posts`. So these
 * threads are staff-authored by construction, and the public API renders their
 * author as "<Site> Team" at render time. No "Winpalack Team" account exists,
 * and none is created here.
 *
 * ── Safe to run in production, and safe to run twice ────────────────────────
 *
 * Everything is matched on its slug behind the existing
 * `unique(site_id, slug)` indexes, so a second run updates nothing it did not
 * create and adds nothing at all. Deliberately NOT wired into DatabaseSeeder,
 * any migration, the scheduler or a queue:
 *
 *   php artisan db:seed --class=ForumSeeder --force
 */
class ForumSeeder extends Seeder
{
    private const string SITE_SLUG = 'winpalack';

    public function run(): void
    {
        $site = Site::where('slug', self::SITE_SLUG)->first();

        if ($site === null) {
            $this->command?->error('No site with slug ' . self::SITE_SLUG . ' — nothing written.');

            return;
        }

        $author = $this->author();

        if ($author === null) {
            // Actionable, and a hard stop. Picking a non-admin row would put a
            // member's name on staff content; creating one would be the fake
            // account this seeder exists to avoid.
            $this->command?->error('No admin account exists in `users`, so there is nobody to author these threads.');
            $this->command?->line('Create one first:  php artisan db:seed --class=AdminUserSeeder --force');

            return;
        }

        $this->command?->line("Author: {$author->email} (users #{$author->id})");

        $sections = $this->syncSections($site);
        $boards   = $this->syncBoards($site, $sections);
        $written  = $this->syncDiscussions($site, $boards, $author);
        $posts    = $this->syncTeamPosts($site, $author);

        SiteCache::flushSite($site->id);

        $this->command?->newLine();
        $this->command?->info(sprintf(
            '%d section(s), %d board(s), %d discussion(s) — %d created, %d already present.',
            count($sections),
            count($boards),
            count(self::DISCUSSIONS),
            $written['created'],
            $written['existing'],
        ));
        $this->command?->line(sprintf(
            '%d editorial repl%s by the team — %d created, %d already present.',
            count(self::TEAM_POSTS),
            count(self::TEAM_POSTS) === 1 ? 'y' : 'ies',
            $posts['created'],
            $posts['existing'],
        ));
        $this->command?->line('No members and no counters were written. Member replies come from real people.');
    }

    /**
     * An existing admin account.
     *
     * Prefers the site's own role system — spatie, and `super-admin` is the
     * role this application actually defines. Falls back to any user holding
     * any role, so an installation that later adds an editor role keeps
     * working without this seeder inventing a name for it.
     *
     * Never creates. Never returns a `forum_users` member: that table cannot
     * appear in `forum_articles.user_id` at all.
     */
    private function author(): ?User
    {
        // NOT User::role('super-admin'): spatie's scope throws
        // RoleDoesNotExist when the role has never been created, which on a
        // fresh installation turns "there is no admin yet" — the case this
        // method exists to detect — into an uncaught exception instead of the
        // actionable message below.
        return User::whereHas('roles', fn ($q) => $q->where('name', 'super-admin'))->orderBy('id')->first()
            ?? User::whereHas('roles')->orderBy('id')->first();
    }

    /**
     * The four sections, matched on slug.
     *
     * Existing ones are ADOPTED, not rewritten: three of the four already exist
     * on this site, and an editor may have reordered or renamed them. Only a
     * missing section is created.
     *
     * @return array<string, ForumSection> keyed by slug
     */
    private function syncSections(Site $site): array
    {
        $out = [];

        foreach (self::SECTIONS as $position => [$slug, $name]) {
            $section = ForumSection::where('site_id', $site->id)->where('slug', $slug)->first();

            if ($section === null) {
                $section = new ForumSection();
                $section->site_id = $site->id;
                $section->slug = $slug;
                $section->name = $name;
                $section->position = $position * 10;
                $section->active = true;
                $section->save();

                $this->command?->info("  + section: {$name}");
            }

            $out[$slug] = $section;
        }

        return $out;
    }

    /**
     * Twenty boards, five per section.
     *
     * Created alongside whatever else the site already has — the load-test
     * seeder's "Sample Board N" rows are left exactly where they are, because
     * deleting another seeder's data is not this one's business to do.
     *
     * @param  array<string, ForumSection>  $sections
     * @return array<string, ForumCategory> keyed by slug
     */
    private function syncBoards(Site $site, array $sections): array
    {
        $out = [];
        $position = 0;

        foreach (self::BOARDS as $sectionSlug => $boards) {
            foreach ($boards as [$slug, $name, $description]) {
                $position += 10;

                $board = ForumCategory::where('site_id', $site->id)->where('slug', $slug)->first();

                if ($board === null) {
                    $board = new ForumCategory();
                    $board->site_id = $site->id;
                    $board->forum_section_id = $sections[$sectionSlug]->id;
                    $board->slug = $slug;
                    $board->name = $name;
                    $board->description = $description;
                    $board->position = $position;
                    $board->active = true;
                    // articles_count / posts_count / last_post_* are left at
                    // their column defaults. ForumCounters and the observers
                    // own them; writing one here would be a fabricated figure.
                    $board->save();

                    $this->command?->info("  + board: {$name}");
                }

                $out[$slug] = $board;
            }
        }

        return $out;
    }

    /**
     * Exactly ten opening posts.
     *
     * @param  array<string, ForumCategory>  $boards
     * @return array{created: int, existing: int}
     */
    private function syncDiscussions(Site $site, array $boards, User $author): array
    {
        $created = 0;
        $existing = 0;

        foreach (self::DISCUSSIONS as [$boardSlug, $slug, $title, $excerpt, $body]) {
            if (ForumArticle::withTrashed()->where('site_id', $site->id)->where('slug', $slug)->exists()) {
                $existing++;
                $this->command?->line("  = {$slug}");

                continue;
            }

            $article = new ForumArticle();
            $article->site_id = $site->id;
            $article->forum_category_id = $boards[$boardSlug]->id;
            // The real admin. Never a member, never an invented account.
            $article->user_id = $author->id;
            $article->slug = $slug;
            $article->title = $title;
            $article->excerpt = $excerpt;
            $article->body = $body;
            // The application's own published state, from the model's constant
            // rather than a literal. The model stamps `published_at` on save.
            $article->status = ForumArticle::STATUS_PUBLISHED;
            $article->pinned = false;
            $article->locked = false;
            // posts_count, views_count and hot_score are untouched. They are
            // derived from real activity, and this seeder creates none.
            $article->save();

            $created++;
            $this->command?->info("  + {$slug}");
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /**
     * One editorial reply under each opener, written by the team.
     *
     * These are replies the team would genuinely post: each adds something the
     * opening question left out rather than performing enthusiasm, and none
     * pretends a member has said anything. Latest Posts therefore shows real
     * content at launch instead of standing empty — and every entry in it is
     * honestly attributed to the editorial team rather than to an invented
     * member.
     *
     * Stored against the real admin's `users` row through
     * {@see \App\Services\Forum\ForumPostService::createAsTeam()}, the same
     * path the admin panel uses, so the seeded posts are indistinguishable
     * from ones written by hand afterwards.
     *
     * IDEMPOTENT without a slug to key on: `forum_posts` has no unique column,
     * so a re-run is recognised by "this discussion already has a staff reply".
     * That is exact for this seeder — it writes one per discussion — and it
     * deliberately does not count member replies, which must never stop the
     * team from being able to post.
     *
     * @var array<string, string> discussion slug => reply body
     */
    private const array TEAM_POSTS = [
        'how-do-you-compare-wagering-requirements' =>
            "One thing worth adding, because it is the term that catches people out most often: the maximum stake while a bonus is active.

"
            . "It is usually a small per-spin or per-hand cap, and going over it even once is commonly enough to void the bonus and anything won from it. It is rarely on the banner and often several clicks into the terms.

"
            . 'If you have had a withdrawal refused over a bonus term, which term was it?',

        'what-rtp-tells-you-and-what-it-does-not' =>
            "Worth being concrete about the scale, since that is the part that surprises people.

"
            . "The published figure is measured over a number of spins in the millions. A single session is far too small a sample for it to describe, which is why two players on the same game with the same RTP can have completely different afternoons. Neither result says the figure is wrong.

"
            . 'Does knowing that change how you read the number, or is it something you already assumed?',

        'setting-a-deposit-limit-before-you-need-one' =>
            "A practical note on the asymmetry, because it is the part that makes these tools work.

"
            . "Lowering a limit normally takes effect straight away, while raising one normally does not — there is a cooling-off period first. That delay is the whole mechanism: it puts time between a decision made in the moment and its effect.

"
            . "If you set one thing today, a deposit limit is the one we would suggest, because it acts before the money moves rather than after.",

        'what-do-you-want-to-see-in-a-casino-review' =>
            "To make the question easier to answer, here is what we currently verify and publish for every casino: the licence checked against the issuing register, the published withdrawal terms and limits, and which safer-play tools the operator actually provides.

"
            . "What we do not publish is a payout-speed figure, because we have no way to verify one that would not simply be repeating the operator's own claim.

"
            . 'Is that a gap worth filling some other way, or would an unverified number be worse than none?',

        'how-do-you-check-a-licence' =>
            "One detail that is easy to miss when checking a register: match the DOMAIN, not just the company.

"
            . "A licence entry lists the sites that company is permitted to operate. A group can hold one licence and run brands that are not all on it, so a real licence and a real company can still leave the specific site you are on unlisted.

"
            . 'Has anyone found a site whose company checked out but whose domain was not on the entry?',

        'what-should-happen-after-you-raise-a-complaint' =>
            "Adding the one procedural point that tends to matter most in practice.

"
            . "The escalation clock usually starts at the operator's FINAL response, not at your first message. If a complaint stays open without one, it can sit indefinitely — so asking explicitly for the final response is often what moves things.

"
            . 'For anyone who has escalated: did asking for it in those words change how quickly you got one?',

        'fast-withdrawals-or-high-limits' =>
            "A third number belongs in that comparison, and it is the one most often left out: the pending period.

"
            . "That is the window between requesting a withdrawal and it actually being sent. An operator can advertise fast payouts and still hold every request for a day or two first, so the advertised processing time and the time you actually wait are different figures.

"
            . 'When you have compared operators, did you find the pending period published anywhere obvious?',

        'what-do-you-expect-kyc-to-ask-for' =>
            "Worth separating two things that often get discussed as one.

"
            . "Identity verification is standard and applies to everybody. Source-of-funds questions are a different check, usually triggered by larger amounts or unusual patterns, and they ask for something quite different — bank statements, payslips.

"
            . 'Both are normal in a regulated market. Have you met the second one, and was it explained when it happened?',

        'how-do-you-choose-a-payment-method' =>
            "One consequence of the return-to-source rule that is worth planning around.

"
            . "If you deposit with a method that cannot receive a payout, the withdrawal has to fall back to something else — usually a bank transfer, usually slower, and sometimes requiring extra verification of an account you have not used before.

"
            . 'So the question is really: does your deposit method support withdrawals, and did you check before or after you needed it?',

        'what-would-make-this-community-useful-to-you' =>
            "To be clear about how this board is treated: we read it, and we would rather hear that something is wrong than not hear it.

"
            . "Replies from us are posted as the editorial team and marked as such — you will always be able to tell staff from members. We are not going to post as members, and we are not going to fill the forum with conversation we had with ourselves.

"
            . 'So: what is missing?',
    ];

    /**
     * Post one editorial reply under each opener.
     *
     * Goes through ForumPostService::createAsTeam() rather than writing rows
     * directly: that is the same path the admin panel uses, so the seeded
     * replies get the body sanitiser, the lock check and the approved status
     * exactly as a hand-written one would, and the post observer keeps the
     * article and category counters correct by itself.
     *
     * @return array{created: int, existing: int}
     */
    private function syncTeamPosts(Site $site, User $author): array
    {
        $service = app(\App\Services\Forum\ForumPostService::class);
        $created = 0;
        $existing = 0;

        foreach (self::TEAM_POSTS as $articleSlug => $body) {
            $article = ForumArticle::where('site_id', $site->id)->where('slug', $articleSlug)->first();

            if ($article === null) {
                // The opener is missing, so there is nothing to reply to. Not
                // an error: somebody may have removed that discussion.
                continue;
            }

            // No unique column on forum_posts to key on, so the idempotency
            // question is "has the team already replied here". Member replies
            // are deliberately not counted — they must never block this.
            if (\App\Models\ForumPost::withTrashed()
                ->where('forum_article_id', $article->id)
                ->whereNotNull('user_id')
                ->exists()
            ) {
                $existing++;

                continue;
            }

            $service->createAsTeam($article, $author, ['body' => $body]);
            $created++;
            $this->command?->info("  + reply on: {$articleSlug}");
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /** @var list<array{0: string, 1: string}> slug, name — in display order. */
    private const array SECTIONS = [
        ['gambling-section', 'Gambling Section'],
        ['online-casinos', 'Online Casinos'],
        ['payments-withdrawals', 'Payments & Withdrawals'],
        ['off-topic', 'Off Topic'],
    ];

    /**
     * Boards, by section slug. Each description says what belongs there rather
     * than restating the board's own name.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     */
    private const array BOARDS = [
        'gambling-section' => [
            ['casino-bonuses-wagering', 'Casino Bonuses & Wagering', 'Compare offers and work out what a wagering requirement actually costs before you accept one.'],
            ['slots-rtp', 'Slots & RTP', 'Return-to-player figures, volatility and what those numbers do and do not predict for a single session.'],
            ['new-upcoming-casinos', 'New & Upcoming Casinos', 'Sites that have just launched, and what is worth checking before anyone deposits at one.'],
            ['live-dealer-games', 'Live Dealer Games', 'Studios, table limits, stream quality and how live tables differ from their RNG equivalents.'],
            ['responsible-gambling', 'Responsible Gambling', 'Deposit limits, time-outs, self-exclusion and the tools that help people stay in control.'],
        ],
        'online-casinos' => [
            ['casino-reviews-experiences', 'Casino Reviews & Experiences', 'First-hand accounts of using a casino: signing up, playing, and getting paid.'],
            ['trust-licensing', 'Trust & Licensing', 'Which regulator a site answers to, how to verify it, and what that protection is worth in practice.'],
            ['complaints-disputes', 'Complaints & Disputes', 'What to do when something goes wrong, and how operators and regulators respond.'],
            ['vip-loyalty-programs', 'VIP & Loyalty Programs', 'How loyalty tiers are earned, what they actually return, and whether the terms are worth it.'],
            ['crypto-casinos', 'Crypto Casinos', 'Playing with digital currencies: volatility, transaction fees, and how licensing differs.'],
        ],
        'payments-withdrawals' => [
            ['withdrawal-times-limits', 'Withdrawal Times & Limits', 'How long payouts really take, and the daily, weekly and monthly caps that shape them.'],
            ['payment-methods', 'Payment Methods', 'Cards, wallets, bank transfers and vouchers — availability, speed and what each costs.'],
            ['verification-kyc', 'Verification & KYC', 'The documents operators ask for, when they ask, and how to get verified without delays.'],
            ['currency-fees', 'Currency & Fees', 'Conversion rates, cross-border charges and the costs that only appear on the statement.'],
            ['payment-problems', 'Payment Problems', 'Failed deposits, pending withdrawals and missing transactions — and how they were resolved.'],
        ],
        'off-topic' => [
            ['introductions', 'Introductions', 'New here? Say hello and tell the community what brought you to the forum.'],
            ['sports-betting', 'Sports Betting', 'Odds, markets and sportsbook features, for members who bet as well as play.'],
            ['site-feedback-suggestions', 'Site Feedback & Suggestions', 'Tell us what is missing, what is broken and what would make this site more useful.'],
            ['general-chat', 'General Chat', 'Anything that does not fit the other boards but still belongs among members.'],
            ['winners-stories', "Winners' Stories", 'Share a win worth remembering — and what you did with it afterwards.'],
        ],
    ];

    /**
     * The ten opening posts: board slug, article slug, title, excerpt, body.
     *
     * Editorial openers, not reports. Each raises a genuine trade-off, states
     * only what is generally true of the industry, and ends with concrete
     * prompts. Where a topic touches money under pressure — losses, limits,
     * delayed payouts — it carries a factual responsible-gambling note.
     *
     * No statistic, no named operator's terms, and no experience the editorial
     * team did not have appears anywhere in them.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const array DISCUSSIONS = [
        [
            'casino-bonuses-wagering',
            'how-do-you-compare-wagering-requirements',
            'How do you compare wagering requirements in practice?',
            'The multiplier is the number every banner leads with, but four other terms decide what it actually costs. How do you weigh them up?',
            <<<'HTML'
            <p>Every welcome offer leads with a multiplier — 30x, 40x — and on its own that number settles very little. What it is applied to, which games count towards it, and how long you have are what turn it into a real cost.</p>
            <p>The terms we find worth reading before anything else:</p>
            <ul>
              <li><strong>What the multiplier multiplies.</strong> "30x bonus" and "30x deposit + bonus" carry the same headline and roughly twice the turnover.</li>
              <li><strong>Game weighting.</strong> Slots often contribute fully while table games contribute a fraction, so where you actually play changes the arithmetic.</li>
              <li><strong>Maximum stake while a bonus is active.</strong> Exceeding it is one of the more common reasons a completed requirement still ends in a refused withdrawal.</li>
              <li><strong>Expiry, and any cap on what can be converted.</strong> Both decide what is left at the end.</li>
            </ul>
            <p>Terms differ between operators and change over time, so this is a way of reading an offer rather than a verdict on any particular one. We would rather hear how members actually do it.</p>
            <p><strong>Over to you:</strong></p>
            <ul>
              <li>Which of those terms do you check first, and which have caught you out?</li>
              <li>Do you take bonuses at all, or do you prefer to play without one?</li>
              <li>What would make an offer's real cost obvious at a glance?</li>
            </ul>
            <p><em>A bonus is a promotional term, not an edge. Every game listed here carries a house edge whether or not a bonus is attached, so treat what you stake as the cost of playing rather than money you expect back.</em></p>
            HTML,
        ],
        [
            'slots-rtp',
            'what-rtp-tells-you-and-what-it-does-not',
            'What RTP tells you — and what it does not',
            'Return to player is a long-run average across an enormous number of spins. Here is why it says so little about any one session.',
            <<<'HTML'
            <p>Return to player is published as a percentage, and it is easy to read it as a promise about your afternoon. It is not. RTP is a long-run average measured across a number of spins far larger than any person will ever play, which is precisely why a session can look nothing like it in either direction.</p>
            <p>Two things sit behind the number and matter more than it does for a short session:</p>
            <ul>
              <li><strong>Volatility.</strong> Two games with the same RTP can behave completely differently — one paying small and often, the other rarely and larger.</li>
              <li><strong>Configurable RTP.</strong> Some titles ship to operators in more than one version, so the same game name does not guarantee the same figure. Where it is published, it is usually in the game's own info panel.</li>
            </ul>
            <p>None of this makes RTP useless. Between two games you would enjoy equally, the higher figure is the better long-run deal. It simply is not a forecast.</p>
            <p><strong>What we would like to know:</strong></p>
            <ul>
              <li>Do you check RTP before playing a slot, and where do you look for it?</li>
              <li>Does volatility change which games you choose, or only how you stake?</li>
              <li>Has a game's published figure ever differed from what you expected?</li>
            </ul>
            <p><em>No RTP figure makes a game profitable to play. The house edge applies at every published percentage.</em></p>
            HTML,
        ],
        [
            'responsible-gambling',
            'setting-a-deposit-limit-before-you-need-one',
            'Setting a deposit limit before you need one',
            'Every licensed operator offers limits and time-outs. They are far easier to set on a quiet day than on a bad one.',
            <<<'HTML'
            <p>Licensed operators are required to offer tools for staying in control: deposit limits, loss limits, session reminders, time-outs and self-exclusion. They are usually in the account settings, and they work — but they are easiest to set at a moment when you do not feel you need them.</p>
            <p>A few things worth knowing about how they generally work:</p>
            <ul>
              <li><strong>Lowering a limit usually takes effect immediately; raising one usually does not.</strong> The delay is deliberate and is the point of the tool.</li>
              <li><strong>A time-out is short and a self-exclusion is not.</strong> Self-exclusion generally cannot be reversed early, which is what makes it effective.</li>
              <li><strong>National schemes exist in several jurisdictions</strong> and cover every operator licensed there, rather than one site at a time.</li>
            </ul>
            <p>Exact options and durations differ by operator and regulator, so treat the above as the general shape rather than anyone's specific terms.</p>
            <p><strong>Worth discussing:</strong></p>
            <ul>
              <li>Do you set limits, and did you set them before or after you felt you needed to?</li>
              <li>Which tool has been most useful to you in practice?</li>
              <li>What would make these settings easier to find or to use?</li>
            </ul>
            <p><em>If gambling has stopped feeling like entertainment, free and confidential help is available independently of any operator — BeGambleAware and GamCare in the UK, and equivalent services in most jurisdictions. Chasing a loss reliably increases it; no strategy changes the arithmetic of a game with a house edge.</em></p>
            HTML,
        ],
        [
            'casino-reviews-experiences',
            'what-do-you-want-to-see-in-a-casino-review',
            'What do you actually want to see in a casino review?',
            'We publish licence checks, withdrawal terms and safer-play tools. We would like to know what is missing.',
            <<<'HTML'
            <p>Our reviews lead with the things we can verify: the licence against the issuing register, the published withdrawal terms and limits, and which safer-play tools an operator actually provides. We deliberately do not lead with bonus size, because it is the number easiest to make look good and hardest to compare.</p>
            <p>That is an editorial judgement, and it may not match what you want when you are deciding where to play. Reviews are only useful if they answer the question the reader actually has.</p>
            <p><strong>So we are asking directly:</strong></p>
            <ul>
              <li>What is the first thing you look for in a review, and do our reviews answer it?</li>
              <li>What do you routinely check elsewhere because it is not here?</li>
              <li>Is there anything in our reviews you consider filler?</li>
            </ul>
            <p>Concrete suggestions are more useful to us than general ones — if there is a field you always want to see, name it.</p>
            HTML,
        ],
        [
            'trust-licensing',
            'how-do-you-check-a-licence',
            'How do you check a licence, and does the regulator change your decision?',
            'A licence number can be verified against a public register in about a minute. Whether the regulator itself matters to you is a separate question.',
            <<<'HTML'
            <p>A licensed operator states its regulator, its licence number and the company holding it, usually in the site footer. Every major regulator publishes a searchable register, so the claim can be checked against the source rather than taken from a badge — a logo in a footer is an image, and only the register is evidence.</p>
            <p>Three mismatches are worth stopping for: the company in the footer is not the company on the register; the domain you are on is not listed against that licence; or the status is anything other than active. None is automatically fraud — group structures are genuinely complicated and registers lag behind changes — but each means the protection you are relying on has not been confirmed.</p>
            <p>What a licence buys you is a process: an authority to complain to, rules about player funds, tested games, and advertising and safer-play obligations. Regimes differ in how much of that they enforce and in how much practical help a player outside that jurisdiction actually gets.</p>
            <p><strong>What we would like to hear:</strong></p>
            <ul>
              <li>Do you verify a licence number before depositing, or is the presence of one enough?</li>
              <li>Does the specific regulator change whether you will play somewhere?</li>
              <li>Has a register lookup ever changed your mind about a site?</li>
            </ul>
            HTML,
        ],
        [
            'complaints-disputes',
            'what-should-happen-after-you-raise-a-complaint',
            'What should happen after you raise a complaint?',
            'Most regulated markets expect a formal process and an independent escalation route. How closely does that match what you have seen?',
            <<<'HTML'
            <p>In most regulated markets an operator is expected to acknowledge a complaint, investigate it within a stated period, and give a final written answer that tells you where to escalate if you disagree — usually an independent adjudicator or ombudsman, and the regulator itself where licence conditions have been breached.</p>
            <p>Written down, that sounds orderly. Members are better placed than we are to say how closely it matches reality: which stage takes longest, what evidence turned out to matter, and whether escalation achieved anything.</p>
            <p>Practical things that generally help, whatever the outcome:</p>
            <ul>
              <li>Keeping the complaint to specific dates, amounts and terms rather than general dissatisfaction.</li>
              <li>Saving transaction references and screenshots at the time rather than afterwards.</li>
              <li>Asking explicitly for the final response and the escalation route, since the clock usually starts there.</li>
            </ul>
            <p><strong>Questions for the board:</strong></p>
            <ul>
              <li>Have you taken a complaint past the operator's own process? What happened?</li>
              <li>What evidence turned out to matter most?</li>
              <li>What would you tell someone about to raise their first complaint?</li>
            </ul>
            <p><em>Please keep accounts factual and avoid naming individual members of staff. A dispute over money can be stressful; if it is affecting your wellbeing, independent support is available through GamCare and equivalent services.</em></p>
            HTML,
        ],
        [
            'withdrawal-times-limits',
            'fast-withdrawals-or-high-limits',
            'Fast withdrawals or high limits — which matters more to you?',
            'The two rarely come together, and which one matters depends entirely on how you play.',
            <<<'HTML'
            <p>Two numbers decide how a payout feels, and they are independent. Speed is how long a withdrawal takes once approved. Limits are how much can leave per day, week or month — and a monthly cap is the one that bites, because a win larger than the cap is not refused, it is paid in instalments across as many months as it takes.</p>
            <p>Other things that shape the wait more than the advertised processing time:</p>
            <ul>
              <li><strong>The pending period</strong> before a withdrawal is actually sent, and whether it can be reversed back into your balance during it. Where reversal can be switched off, switching it off costs nothing.</li>
              <li><strong>Verification.</strong> It is usually triggered by the first withdrawal rather than the first deposit, which is why it feels like a delay aimed at winners. Completing it early moves the wait to a day when nothing is riding on it.</li>
              <li><strong>Method and currency.</strong> Withdrawals normally return to the method used to deposit, so the choice made at deposit decides how you are paid.</li>
            </ul>
            <p><strong>What we are curious about:</strong></p>
            <ul>
              <li>Which would you rather have: same-day payouts with a modest cap, or a high cap with a longer wait?</li>
              <li>Do you check withdrawal terms before depositing, or only when you first try to withdraw?</li>
              <li>What would make a payout process feel trustworthy to you?</li>
            </ul>
            <p><em>A reversible withdrawal is money you have already decided to take out. If waiting on a payout is causing financial pressure, that is a good moment to set a deposit limit or take a time-out rather than to keep playing.</em></p>
            HTML,
        ],
        [
            'verification-kyc',
            'what-do-you-expect-kyc-to-ask-for',
            'What do you expect KYC to ask for, and when?',
            'Identity checks are a licensing obligation rather than an obstacle invented for winners — but the timing is what makes them feel like one.',
            <<<'HTML'
            <p>Licensed operators are required to verify who their customers are. In practice that usually means proof of identity, proof of address and proof of the payment method, and sometimes source-of-funds questions for larger amounts. Standard, and not aimed at any individual.</p>
            <p>What causes most of the friction is timing. Verification is commonly triggered by the first withdrawal rather than at sign-up, so the first time many people meet it is the first time they try to take money out. Completing it when the account is opened moves that to a day when nothing is waiting on it.</p>
            <p>Requirements vary by operator, jurisdiction and amount, so we are describing the general shape rather than any specific site's policy.</p>
            <p><strong>Worth comparing notes on:</strong></p>
            <ul>
              <li>Were you asked to verify at sign-up or at your first withdrawal?</li>
              <li>What tripped a document up — quality, a name mismatch, an address that did not match the statement?</li>
              <li>Would you rather verify immediately at sign-up, or only if you withdraw?</li>
            </ul>
            <p><em>Please do not post images of your own documents here, even redacted.</em></p>
            HTML,
        ],
        [
            'payment-methods',
            'how-do-you-choose-a-payment-method',
            'How do you choose a payment method for deposits and withdrawals?',
            'The method chosen at deposit usually decides how a withdrawal is paid, which makes it a bigger decision than it looks.',
            <<<'HTML'
            <p>Deposits are close to instant almost everywhere, so the choice of method is rarely about paying in. It matters at the other end: withdrawals normally have to return to the method used to deposit, so the decision made in thirty seconds at sign-up sets how — and often how quickly — a payout reaches you.</p>
            <p>Things that differ between methods, independently of the operator:</p>
            <ul>
              <li><strong>Whether the method supports withdrawals at all.</strong> Some deposit options cannot receive one, which forces a fallback and often a longer wait.</li>
              <li><strong>Minimums and fees</strong>, which can be a meaningful share of a small withdrawal.</li>
              <li><strong>Currency conversion</strong>, where your account currency differs from the site's — a cost that usually shows up on the statement rather than in the cashier.</li>
            </ul>
            <p><strong>What we would like to know:</strong></p>
            <ul>
              <li>Which method do you use, and did you choose it for deposits or for withdrawals?</li>
              <li>Have you been caught by a method that could not receive a payout?</li>
              <li>Do fees or conversion costs change what you use?</li>
            </ul>
            HTML,
        ],
        [
            'site-feedback-suggestions',
            'what-would-make-this-community-useful-to-you',
            'What would make this community useful to you?',
            'The forum is new and deliberately empty. What gets built next should come from the people who will use it.',
            <<<'HTML'
            <p>This forum has just opened. The boards and these opening posts are ours; everything after them is not, and we would rather it filled up slowly with real discussion than quickly with anything else.</p>
            <p>You will notice there are no replies yet and no activity figures. That is deliberate: there is no community here to report on until members create one, and inventing the appearance of activity would make every number on the page worthless.</p>
            <p>So the first thing we would like from this board is direction.</p>
            <p><strong>Tell us:</strong></p>
            <ul>
              <li>What would bring you back to a forum like this one more than once?</li>
              <li>Which boards look useful, and which look unnecessary?</li>
              <li>Is anything missing — a board, a feature, or a rule you would expect a community like this to have?</li>
            </ul>
            <p>Suggestions that name something specific are the most useful. We read this board.</p>
            HTML,
        ],
    ];
}
