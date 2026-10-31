<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CmsPage;
use App\Models\Site;
use App\Support\TrustPageDrafts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Loads the trust-page drafts ({@see TrustPageDrafts}) into a site's CMS pages
 * WITHOUT publishing anything.
 *
 *  - A page that already exists gets the text as a pending draft beside its live
 *    copy (`draft_*` columns). The live page keeps serving unchanged until an
 *    editor presses "Publish draft" in the admin.
 *  - A page that does not exist yet (How We Rate, on most sites) is created with
 *    status `draft`, which the public API never serves.
 *
 * An existing pending draft is left alone unless `--force`: it may already hold
 * an editor's revisions. Run `--dry-run` first to see what would happen.
 *
 *   php artisan sites:draft-trust-pages winpalack --dry-run
 */
class DraftSiteTrustPages extends Command
{
    protected $signature = 'sites:draft-trust-pages
                            {site : Site slug or numeric id}
                            {--force : Overwrite a pending draft that already exists}
                            {--dry-run : Report what would happen and write nothing}';

    protected $description = "Load reviewable drafts of the trust pages into a site's CMS pages, publishing nothing";

    public function handle(): int
    {
        $identifier = trim((string) $this->argument('site'));
        $site = ctype_digit($identifier)
            ? Site::query()->find((int) $identifier)
            : Site::query()->where('slug', $identifier)->first();

        if ($site === null) {
            $this->error('No site matches "' . $identifier . '".');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $plan = [];

        foreach (TrustPageDrafts::forBrand((string) $site->name, (string) $site->domain) as $draft) {
            $page = CmsPage::query()->where('site_id', $site->id)->where('slug', $draft['slug'])->first();

            if ($page === null) {
                $plan[] = ['action' => 'create', 'draft' => $draft, 'page' => null];
            } elseif ($page->hasDraftContent() && ! $force) {
                $plan[] = ['action' => 'skip', 'draft' => $draft, 'page' => $page];
            } else {
                $plan[] = ['action' => 'draft', 'draft' => $draft, 'page' => $page];
            }
        }

        $this->table(['Page', 'Action'], array_map(static fn (array $p): array => [
            $p['draft']['slug'],
            match ($p['action']) {
                'create' => 'create as a new DRAFT page (not public)',
                'draft'  => 'save as pending draft; live page (' . $p['page']->status . ') unchanged',
                default  => 'skip — a pending draft already exists (use --force)',
            },
        ], $plan));

        if ($dryRun) {
            $this->comment('  Dry run — nothing written.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($plan, $site): void {
                foreach ($plan as $item) {
                    $draft = $item['draft'];

                    if ($item['action'] === 'create') {
                        CmsPage::create([
                            'site_id'          => $site->id,
                            'slug'             => $draft['slug'],
                            'title'            => $draft['title'],
                            'content'          => $draft['content'],
                            'meta_title'       => $draft['meta_title'],
                            'meta_description' => $draft['meta_description'],
                            'status'           => CmsPage::STATUS_DRAFT,
                            'noindex'          => false,
                        ]);
                    } elseif ($item['action'] === 'draft') {
                        $item['page']->update([
                            'draft_title'            => $draft['title'],
                            'draft_content'          => $draft['content'],
                            'draft_meta_title'       => $draft['meta_title'],
                            'draft_meta_description' => $draft['meta_description'],
                        ]);
                    }
                }
            });
        } catch (Throwable $e) {
            $this->error('Write failed, nothing was changed: ' . $e->getMessage());

            return self::FAILURE;
        }

        // Nothing public changed, so there is no cache to flush.
        $this->info('  Drafts saved. Review them in the admin (CMS Pages) and publish each one when ready.');

        return self::SUCCESS;
    }
}
