<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RevalidateNextJsSites;
use App\Models\CmsPage;
use App\Models\Site;
use App\Support\LegalPageContent;
use App\Support\SiteCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Converts a site's stored legal pages to legal-identity TOKENS.
 *
 * ── Why ─────────────────────────────────────────────────────────────────────
 *
 * Pages used to carry the operator's identity as text: bracketed placeholders
 * from the seed, later overwritten with real values by the command this one
 * replaces. A wrong value written that way (a postcode where the company name
 * belongs) then sat in three pages with nothing tying it to its source. With
 * tokens, the value lives once on the site row (admin → Sites → Edit → Legal
 * identity) and every page, the footer and the JSON-LD read it — an empty value
 * hides its line instead of printing anything. See App\Support\LegalIdentity.
 *
 * ── What it replaces ────────────────────────────────────────────────────────
 *
 * Always the three bracketed seed placeholders. Plus, when given, the exact
 * text a page currently shows in their place: `--entity-was`, `--address-was`,
 * `--jurisdiction-was` (each repeatable). Literal, case-sensitive matches only,
 * in `content` and in a pending `draft_content` — never a guess at a sentence
 * pattern. Always run `--dry-run` first: it prints each match with the words
 * around it, so a value that also occurs somewhere unrelated is caught before
 * anything is written.
 *
 * ── Usage ───────────────────────────────────────────────────────────────────
 *
 *   php artisan sites:tokenize-legal-identity winpalack \
 *       --entity-was="SW1Y 4PH" --jurisdiction-was="England and Wales" --dry-run
 *
 * Idempotent: a second run finds nothing. Without `--dry-run` it writes in one
 * transaction, flushes the site's cache and revalidates the changed pages.
 */
class TokenizeSiteLegalIdentity extends Command
{
    protected $signature = 'sites:tokenize-legal-identity
                            {site : Site slug or numeric id}
                            {--entity-was=* : Text currently printed where the legal entity belongs}
                            {--address-was=* : Text currently printed where the registered address belongs}
                            {--jurisdiction-was=* : Text currently printed where the governing law belongs}
                            {--dry-run : Report what would change and write nothing}';

    protected $description = "Replace the legal identity written into a site's pages with tokens read from its settings";

    /** Characters of context shown on each side of a match in the report. */
    private const int CONTEXT = 40;

    public function handle(): int
    {
        $site = $this->resolveSite((string) $this->argument('site'));

        if ($site === null) {
            $this->error('No site matches "' . $this->argument('site') . '".');

            return self::FAILURE;
        }

        $replacements = $this->replacements();
        $dryRun = (bool) $this->option('dry-run');

        $this->info(sprintf('%s legal identity for %s (%s)', $dryRun ? 'Would tokenize' : 'Tokenizing', $site->name, $site->slug));

        $pages = CmsPage::query()->where('site_id', $site->id)->orderBy('slug')->get();
        $rows = [];
        $changed = [];

        foreach ($pages as $page) {
            $updates = [];

            foreach (['content', 'draft_content'] as $column) {
                $text = (string) $page->getAttribute($column);

                foreach ($replacements as $search => $token) {
                    $offset = 0;

                    while (($at = strpos($text, $search, $offset)) !== false) {
                        $rows[] = [$page->slug, $column, $token, $this->snippetAround($text, $at, strlen($search))];
                        $offset = $at + strlen($search);
                    }

                    $text = str_replace($search, $token, $text);
                }

                if ($text !== (string) $page->getAttribute($column)) {
                    $updates[$column] = $text;
                }
            }

            if ($updates !== []) {
                $changed[] = ['page' => $page, 'updates' => $updates];
            }
        }

        if ($rows === []) {
            $this->line('  Nothing to tokenize — none of the given text was found.');

            return self::SUCCESS;
        }

        $this->table(['Page', 'Column', 'Becomes', 'Context'], $rows);

        if ($dryRun) {
            $this->comment(sprintf('  Dry run — %d replacement(s) across %d page(s). Nothing written.', count($rows), count($changed)));

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($changed): void {
                foreach ($changed as $entry) {
                    /** @var CmsPage $page */
                    $page = $entry['page'];
                    // forceFill: the draft timestamp must not move just because
                    // a token replaced text inside an existing draft.
                    $page->forceFill($entry['updates'])->saveQuietly();
                }
            });
        } catch (Throwable $e) {
            $this->error('Write failed, nothing was changed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->invalidate($site, array_map(static fn (array $e): string => (string) $e['page']->slug, $changed));

        $this->info(sprintf('  Replaced %d occurrence(s) across %d page(s).', count($rows), count($changed)));
        $this->line('  Now fill the values in the admin: Sites → Edit → Legal identity. Empty ones stay hidden.');

        return self::SUCCESS;
    }

    /** @return array<string, string> search text => token, longest first */
    private function replacements(): array
    {
        $map = [
            LegalPageContent::PLACEHOLDER_ENTITY       => '{{legal_entity_name}}',
            LegalPageContent::PLACEHOLDER_ADDRESS      => '{{registered_address}}',
            LegalPageContent::PLACEHOLDER_JURISDICTION => '{{governing_law}}',
        ];

        foreach (['entity-was' => '{{legal_entity_name}}', 'address-was' => '{{registered_address}}', 'jurisdiction-was' => '{{governing_law}}'] as $option => $token) {
            foreach ((array) $this->option($option) as $value) {
                $value = trim((string) $value);

                if ($value !== '') {
                    $map[$value] = $token;
                }
            }
        }

        // Longest first, so a full address is replaced before a postcode that
        // happens to be part of it.
        uksort($map, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $map;
    }

    private function snippetAround(string $text, int $at, int $length): string
    {
        $start = max(0, $at - self::CONTEXT);
        $snippet = substr($text, $start, $length + 2 * self::CONTEXT);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($snippet)));
    }

    /**
     * Same invalidation an admin save performs. Best-effort: the content is
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
                    array_map(static fn (string $slug): string => 'page:' . $slug, array_values(array_unique($slugs))),
                    [$site->id],
                );
            }
        } catch (Throwable $e) {
            $this->warn('  Content saved, but invalidation failed: ' . $e->getMessage());
        }
    }

    private function resolveSite(string $identifier): ?Site
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return ctype_digit($identifier)
            ? Site::query()->find((int) $identifier)
            : Site::query()->where('slug', $identifier)->first();
    }
}
