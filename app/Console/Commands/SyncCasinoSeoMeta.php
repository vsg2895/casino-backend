<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Casino;
use Illuminate\Console\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Terminal;

/**
 * Applies the editorial SEO metadata (`meta_title`, `meta_description`) for the
 * casinos shipped with the platform.
 *
 * Replaces the old `database/casino-seo-meta.sql` dump, and is deliberately the
 * SAME operation: for each slug below, set both columns. Matching on `slug`
 * rather than `id` is what makes it safe on any environment — ids differ between
 * databases, slugs do not — and re-running it is a no-op because the values are
 * fixed here rather than accumulated.
 *
 * Why a command instead of the .sql file:
 *  - it runs through the app's configured connection, so there is no chance of
 *    pointing mysql at the wrong database or forgetting credentials;
 *  - it reports what changed, and `--dry-run` shows the blast radius first;
 *  - values are validated for SEO length limits before anything is written, so a
 *    future edit here cannot silently ship a title Google will truncate;
 *  - it lives in the repo next to the code that consumes it, so it is covered by
 *    the same lint and review as everything else.
 *
 * IMPORTANT — these values are SHARED by every site, because a casino is global
 * master data. Each public site appends its own signature line at render time
 * (see `COPY.casinos.reviewSignature`), which is what keeps the four domains from
 * shipping an identical description. Do not add per-site wording here; it would
 * have nowhere to go.
 */
class SyncCasinoSeoMeta extends Command
{
    protected $signature = 'casinos:seo-meta
        {--dry-run : Show what would change without writing anything}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Apply the editorial SEO meta title and description to the shipped casinos';

    /**
     * Google truncates a title around 60 characters and a description around 160.
     * Both budgets have to allow for what the frontend appends — " | <Brand>" on
     * the title, and the per-site signature on the description.
     */
    private const int MAX_TITLE = 60;
    private const int MAX_DESCRIPTION = 160;

    /** Longest values the frontend appends, reserved out of the budgets above. */
    private const int RESERVED_TITLE = 19;        // ' | Idev Affiliation'
    private const int RESERVED_DESCRIPTION = 36;  // ' License and payout limits verified.'

    /**
     * Keyed by casino slug — the same seven rows the .sql file carried, verbatim.
     *
     * CAREFUL: PHP silently converts a numeric-string array key to an integer, so
     * the '7' below is stored as int 7. Every read of a key from this map is cast
     * back to string before it reaches the query builder — `where('slug', 7)`
     * makes MySQL compare the varchar column NUMERICALLY, which casts 'bitstarz'
     * to a double and blows up the whole statement.
     *
     * @var array<string, array{title: string, description: string}>
     */
    private const array META = [
        'bitstarz' => [
            'title'       => 'BitStarz Casino Review & Bonuses',
            'description' => 'BitStarz casino review: welcome bonus, free spins, game range and how fast withdrawals land. Rated 5/5.',
        ],
        'rocketplay' => [
            'title'       => 'RocketPlay Casino Review & Bonus',
            'description' => 'RocketPlay casino review: sign-up offer, game library, payment methods and payout speed. Rated 5/5.',
        ],
        '7-bit-casino' => [
            'title'       => '7 Bit Casino Review & Free Spins',
            'description' => '7 Bit Casino review: welcome package, free spins, crypto deposits and cashout times. Rated 5/5.',
        ],
        'luckydreams' => [
            'title'       => 'Luckydreams Casino Review & Bonus',
            'description' => 'Luckydreams casino review: welcome offer, free spins, slot range and withdrawal limits. Rated 5/5.',
        ],
        'woocasino' => [
            'title'       => 'WooCasino Review: Bonuses & Payouts',
            'description' => 'WooCasino review: welcome bonus terms, game providers, deposit options and payout speed. Rated 4/5.',
        ],
        'playamo' => [
            'title'       => 'PlayAmo Casino Review & Bonuses',
            'description' => 'PlayAmo casino review: welcome bonus, live dealer tables, banking options and payout times. Rated 4/5.',
        ],
        '7' => [
            'title'       => '7 Casino Review: Bonuses & Games',
            'description' => '7 Casino review: welcome bonus, game selection, payment methods and how quickly winnings arrive. Rated 4/5.',
        ],
    ];

    public function handle(): int
    {
        if (($invalid = $this->lengthProblems()) !== []) {
            $this->error('Refusing to write — these values exceed the SEO budgets:');
            foreach ($invalid as $line) {
                $this->line('  ' . $line);
            }

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $plan = $this->plan();

        if ($plan === []) {
            $this->warn('None of the expected casino slugs exist in this database. Nothing to do.');

            return self::SUCCESS;
        }

        $this->renderPlan($plan);

        $missing = array_diff(
            array_map('strval', array_keys(self::META)),
            array_map('strval', array_column($plan, 'slug')),
        );
        foreach ($missing as $slug) {
            $this->warn("No casino with slug '{$slug}' in this database — skipped.");
        }

        if ($dryRun) {
            $this->info('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $changes = array_values(array_filter($plan, static fn (array $r): bool => $r['state'] !== 'unchanged'));

        if ($changes === []) {
            $this->info('Every casino already carries these values. Nothing to write.');

            return self::SUCCESS;
        }

        if (! $this->confirmWrite($changes)) {
            $this->info('Aborted — nothing was written.');

            return self::SUCCESS;
        }

        // Same effect as the seven UPDATE ... WHERE slug = ? statements: set both
        // columns for each slug. Only rows that actually differ are touched, so
        // `updated_at` is not churned on a re-run.
        $written = 0;
        foreach ($changes as $row) {
            Casino::query()->where('slug', (string) $row['slug'])->update([
                'meta_title'       => self::META[$row['slug']]['title'],
                'meta_description' => self::META[$row['slug']]['description'],
            ]);
            $written++;
        }

        $this->info("Applied SEO metadata to {$written} casino(s).");

        return self::SUCCESS;
    }

    /**
     * Show the plan: BOTH columns the command writes, not just the title.
     *
     * The description is the value most likely to be wrong (it is long, and the
     * length budget is tight), so reviewing it is the whole point of --dry-run —
     * showing only the title meant half of every write went unseen. It is wrapped
     * to the space actually available rather than truncated: a description you
     * cannot read in full is one you cannot approve. Rows are separated because a
     * wrapped cell spans several lines and would otherwise blur into the next.
     *
     * @param  list<array{slug: string, state: string, title: string, description: string}>  $plan
     */
    private function renderPlan(array $plan): void
    {
        $width = $this->descriptionWidth();
        $rows = [];

        foreach ($plan as $index => $row) {
            if ($index > 0) {
                $rows[] = new TableSeparator();
            }

            $rows[] = [$row['slug'], $row['state'], $row['title'], $row['description']];
        }

        (new Table($this->output))
            ->setHeaders(['Slug', 'Change', 'Meta title', 'Meta description'])
            ->setRows($rows)
            ->setColumnMaxWidth(3, $width)
            ->render();

        $this->newLine();
    }

    /**
     * Width to wrap the description column to.
     *
     * The other three columns plus the borders cost roughly 70 columns, so the
     * description gets whatever the terminal has left — clamped so it stays
     * readable in a narrow window and does not sprawl in a maximised one.
     */
    private function descriptionWidth(): int
    {
        return max(40, min(80, (new Terminal())->getWidth() - 70));
    }

    /**
     * What each slug would become, and whether that is a change.
     *
     * @return list<array{slug: string, state: string, title: string, description: string}>
     */
    private function plan(): array
    {
        $existing = Casino::query()
            ->whereIn('slug', array_keys(self::META))
            ->get(['slug', 'meta_title', 'meta_description'])
            ->keyBy('slug');

        $plan = [];

        foreach (self::META as $slug => $meta) {
            $slug = (string) $slug;   // int 7 for the casino literally named "7"
            $casino = $existing->get($slug);

            if ($casino === null) {
                continue;
            }

            $current = [(string) $casino->meta_title, (string) $casino->meta_description];
            $target = [$meta['title'], $meta['description']];

            $plan[] = [
                'slug'  => $slug,
                'state' => $current === $target
                    ? 'unchanged'
                    : (($current[0] === '' && $current[1] === '') ? 'fill' : 'overwrite'),
                'title'       => $meta['title'],
                'description' => $meta['description'],
            ];
        }

        return $plan;
    }

    /**
     * Values that would not survive a search result, with the frontend's own
     * additions accounted for.
     *
     * @return list<string>
     */
    private function lengthProblems(): array
    {
        $problems = [];
        $titleBudget = self::MAX_TITLE - self::RESERVED_TITLE;
        $descBudget = self::MAX_DESCRIPTION - self::RESERVED_DESCRIPTION;

        foreach (self::META as $slug => $meta) {
            $slug = (string) $slug;

            if (mb_strlen($meta['title']) > $titleBudget) {
                $problems[] = sprintf(
                    "%s: title is %d chars, budget is %d (60 minus the ' | Brand' suffix)",
                    $slug, mb_strlen($meta['title']), $titleBudget,
                );
            }

            if (mb_strlen($meta['description']) > $descBudget) {
                $problems[] = sprintf(
                    '%s: description is %d chars, budget is %d (160 minus the per-site signature)',
                    $slug, mb_strlen($meta['description']), $descBudget,
                );
            }
        }

        return $problems;
    }

    /**
     * Confirm before overwriting. Skipped by --force, which is what a deploy
     * script uses — same convention as `migrate --force`.
     *
     * @param  list<array{slug: string, state: string, title: string, description: string}>  $changes
     */
    private function confirmWrite(array $changes): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $overwrites = array_values(array_filter($changes, static fn (array $r): bool => $r['state'] === 'overwrite'));

        if ($overwrites !== []) {
            $slugs = implode(', ', array_column($overwrites, 'slug'));
            $this->warn("This REPLACES metadata already set on: {$slugs}");
        }

        return $this->confirm('Write these values to ' . count($changes) . ' casino(s)?', true);
    }
}
