<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RevalidateNextJsSites;
use App\Models\CmsPage;
use App\Models\Site;
use App\Support\LegalIdentity;
use App\Support\LegalPageContent;
use App\Support\SiteCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Sets a site's legal identity and makes its legal pages read it — the one
 * command to run for every new site.
 *
 * ── What it does, in one transaction ────────────────────────────────────────
 *
 *  1. Writes the values given to the site row — the same columns as admin →
 *     Sites → Edit → Legal identity. Only the options given are written; an
 *     option given as "" clears that field, which hides its line on the public
 *     pages (see App\Support\LegalIdentity).
 *  2. Converts identity TEXT inside the site's pages to tokens, so the pages,
 *     the footer and the JSON-LD all read the value from the row:
 *       - the three bracketed seed placeholders, always;
 *       - the entity, address and governing law being set, where a page already
 *         carries them as text (what the retired sites:fill-legal-identity
 *         wrote);
 *       - `--entity-was`, `--address-was`, `--jurisdiction-was` (repeatable):
 *         OLD text a page shows in their place, e.g. a previous company name;
 *       - the role mailboxes older seeds printed as text (dpo@, legal@,
 *         compliance@, partners@ + the site's domain): dpo@ becomes the privacy
 *         address, the others the contact address, and the two sentences that
 *         offered dpo@ BESIDE the privacy address lose that clause rather than
 *         naming one inbox twice.
 *     Literal, case-sensitive matches only, in `content` and in a pending
 *     `draft_content` — never a guess at a sentence pattern.
 *  3. Flushes the site's cache and revalidates its pages, as an admin save does.
 *
 * Pages seeded today already carry tokens, so on a new site step 2 finds
 * nothing and only step 1 writes. Idempotent: a second run changes nothing.
 *
 * ── Usage ───────────────────────────────────────────────────────────────────
 *
 *   php artisan sites:set-legal-identity fsozbet \
 *       --entity="Fsozbet LTD" \
 *       --address="48 Meadowfield Drive, Nottingham, NG5 3JL, United Kingdom" \
 *       --contact-email=info@fsozbet.com --dry-run
 *
 * Always run `--dry-run` first: it prints every field before/after and every
 * page match with the surrounding words, so a value that also occurs somewhere
 * unrelated is caught before anything is written.
 */
class SetSiteLegalIdentity extends Command
{
    protected $signature = 'sites:set-legal-identity
                            {site : Site slug or numeric id}
                            {--entity= : Legal entity name, e.g. "Example LTD"}
                            {--address= : Registered address}
                            {--company-number= : Company registration number}
                            {--law= : Governing law, e.g. "England and Wales"}
                            {--contact-email= : General contact e-mail (support and privacy fall back to it)}
                            {--support-email= : Support e-mail}
                            {--privacy-email= : Privacy / data-protection e-mail}
                            {--entity-was=* : Old text printed in the pages where the legal entity belongs}
                            {--address-was=* : Old text printed in the pages where the registered address belongs}
                            {--jurisdiction-was=* : Old text printed in the pages where the governing law belongs}
                            {--dry-run : Report what would change and write nothing}';

    protected $description = "Set a site's legal identity and point its legal pages, footer and JSON-LD at it";

    /** option => [column, max length], lengths as in 2026_11_20_100000. */
    private const array FIELDS = [
        'entity'         => ['legal_entity_name', 200],
        'address'        => ['registered_address', 500],
        'company-number' => ['company_number', 60],
        'law'            => ['governing_law', 120],
        'contact-email'  => ['contact_email', 190],
        'support-email'  => ['support_email', 190],
        'privacy-email'  => ['privacy_email', 190],
    ];

    /** Options whose value, found as text in a page, becomes its token. */
    private const array TOKENIZED = [
        'entity'  => ['entity-was', '{{legal_entity_name}}'],
        'address' => ['address-was', '{{registered_address}}'],
        'law'     => ['jurisdiction-was', '{{governing_law}}'],
    ];

    /** Characters of context shown on each side of a match in the report. */
    private const int CONTEXT = 40;

    public function handle(): int
    {
        if (! Schema::hasColumn('sites', 'legal_entity_name')) {
            $this->error('The legal identity columns do not exist yet. Run `php artisan migrate --force` first.');

            return self::FAILURE;
        }

        $identifier = trim((string) $this->argument('site'));
        $site = ctype_digit($identifier)
            ? Site::query()->find((int) $identifier)
            : Site::query()->where('slug', $identifier)->first();

        if ($site === null) {
            $this->error('No site matches "' . $identifier . '".');

            return self::FAILURE;
        }

        $fields = $this->fieldUpdates();

        if ($fields === null) {
            return self::FAILURE;
        }

        $pages = $this->pageUpdates($site, $this->replacements($site));

        if ($fields === [] && $pages['changed'] === []) {
            $this->error('Nothing to do — pass at least one of: --' . implode(', --', array_keys(self::FIELDS)) . '.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf('%s legal identity for %s (%s)', $dryRun ? 'Would set' : 'Setting', $site->name, $site->slug));

        if ($fields !== []) {
            $this->table(['Field', 'Now', 'Becomes'], array_map(
                static fn (string $column, ?string $value): array => [$column, (string) ($site->getAttribute($column) ?? '—'), $value ?? '— (cleared)'],
                array_keys($fields),
                array_values($fields),
            ));
        }

        if ($pages['rows'] === []) {
            $this->line('  Pages: no identity text to convert — they already read the site settings.');
        } else {
            $this->table(['Page', 'Column', 'Becomes', 'Context'], $pages['rows']);
        }

        if ($dryRun) {
            $this->comment(sprintf('  Dry run — %d field(s), %d page replacement(s). Nothing written.', count($fields), count($pages['rows'])));

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($site, $fields, $pages): void {
                if ($fields !== []) {
                    $site->forceFill($fields)->save();
                }

                foreach ($pages['changed'] as $entry) {
                    // forceFill + quiet: the draft timestamp must not move just
                    // because a token replaced text inside an existing draft.
                    $entry['page']->forceFill($entry['updates'])->saveQuietly();
                }
            });
        } catch (Throwable $e) {
            $this->error('Write failed, nothing was changed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->invalidate($site, array_map(static fn (array $e): string => (string) $e['page']->slug, $pages['changed']));

        $this->info(sprintf('  Saved %d field(s); replaced %d occurrence(s) across %d page(s).', count($fields), count($pages['rows']), count($pages['changed'])));

        $missing = LegalIdentity::missingFor($site->fresh());

        if ($missing !== []) {
            $this->warn('  Still empty (their lines stay hidden): ' . implode(', ', $missing));
        }

        return self::SUCCESS;
    }

    /**
     * The site columns to write, or null after printing a validation error.
     *
     * @return array<string, ?string>|null
     */
    private function fieldUpdates(): ?array
    {
        $updates = [];

        foreach (self::FIELDS as $option => [$column, $max]) {
            $raw = $this->option($option);

            if ($raw === null) {
                continue; // not given: leave the field alone
            }

            $value = trim((string) $raw);

            if (mb_strlen($value) > $max) {
                $this->error("--{$option} is longer than {$max} characters.");

                return null;
            }

            if ($value !== '' && str_ends_with($column, '_email') && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $this->error("--{$option} is not a valid e-mail address: {$value}");

                return null;
            }

            $updates[$column] = $value === '' ? null : $value;
        }

        return $updates;
    }

    /** @return array<string, string> search text => token ('' removes it), longest first */
    private function replacements(Site $site): array
    {
        $map = [
            LegalPageContent::PLACEHOLDER_ENTITY       => '{{legal_entity_name}}',
            LegalPageContent::PLACEHOLDER_ADDRESS      => '{{registered_address}}',
            LegalPageContent::PLACEHOLDER_JURISDICTION => '{{governing_law}}',
        ];

        foreach (self::TOKENIZED as $option => [$wasOption, $token]) {
            foreach ([$this->option($option), ...(array) $this->option($wasOption)] as $value) {
                $value = trim((string) $value);

                if ($value !== '') {
                    $map[$value] = $token;
                }
            }
        }

        $domain = trim((string) $site->domain);

        if ($domain !== '') {
            $dpo = 'dpo@' . $domain;
            $map[' (Data Protection contact: <a href="mailto:' . $dpo . '">' . $dpo . '</a>)'] = '';
            $map[' or our Data Protection contact at <a href="mailto:' . $dpo . '">' . $dpo . '</a>'] = '';
            $map[$dpo] = '{{privacy_email}}';

            foreach (['legal', 'compliance', 'partners'] as $role) {
                $map[$role . '@' . $domain] = '{{contact_email}}';
            }
        }

        // Longest first, so a full address is replaced before a postcode that
        // happens to be part of it.
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $map;
    }

    /**
     * @param  array<string, string>  $replacements
     * @return array{rows: list<array{0: string, 1: string, 2: string, 3: string}>, changed: list<array{page: CmsPage, updates: array<string, string>}>}
     */
    private function pageUpdates(Site $site, array $replacements): array
    {
        $rows = [];
        $changed = [];

        foreach (CmsPage::query()->where('site_id', $site->id)->orderBy('slug')->get() as $page) {
            $updates = [];

            foreach (['content', 'draft_content'] as $column) {
                $original = (string) $page->getAttribute($column);
                $text = $original;

                foreach ($replacements as $search => $token) {
                    $offset = 0;

                    while (($at = strpos($text, $search, $offset)) !== false) {
                        $rows[] = [(string) $page->slug, $column, $token === '' ? '(removed)' : $token, $this->snippetAround($text, $at, strlen($search))];
                        $offset = $at + strlen($search);
                    }

                    $text = str_replace($search, $token, $text);
                }

                if ($text !== $original) {
                    $updates[$column] = $text;
                }
            }

            if ($updates !== []) {
                $changed[] = ['page' => $page, 'updates' => $updates];
            }
        }

        return ['rows' => $rows, 'changed' => $changed];
    }

    private function snippetAround(string $text, int $at, int $length): string
    {
        $start = max(0, $at - self::CONTEXT);
        $snippet = substr($text, $start, $length + 2 * self::CONTEXT);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($snippet)));
    }

    /**
     * Same invalidation an admin save performs. Best-effort: the data is
     * already written.
     *
     * @param  list<string>  $slugs
     */
    private function invalidate(Site $site, array $slugs): void
    {
        try {
            SiteCache::flushSite((int) $site->id);

            if ($site->active) {
                RevalidateNextJsSites::dispatch(
                    ['editorial', ...array_map(static fn (string $slug): string => 'page:' . $slug, array_values(array_unique($slugs)))],
                    [$site->id],
                );
            }
        } catch (Throwable $e) {
            $this->warn('  Saved, but invalidation failed: ' . $e->getMessage());
        }
    }
}
