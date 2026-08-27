<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CmsPage;
use App\Models\Site;
use App\Support\LegalPageContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-generates the meta description of the standard legal pages so each site's
 * carries its own positioning.
 *
 * WHY A COMMAND. `CmsPageService::seedDefaultsForSite()` is idempotent by
 * design — it creates missing pages and never touches existing ones, so admin
 * edits survive a re-seed. That is the right default, and it also means a change
 * to the generator reaches NEW sites only. The pages already live on the four
 * running domains keep whatever they were seeded with, which is the identical
 * template description this exists to replace.
 *
 * WHAT IT WILL NOT OVERWRITE. A page whose description no longer matches the
 * generated default has been edited by a person, and their wording wins — always,
 * even under --force. The check is an exact comparison against what the generator
 * produces for that brand with NO positioning, i.e. the previous output. So:
 *
 *   description == old generated default   -> nobody edited it   -> refresh
 *   description != old generated default   -> a human wrote it   -> leave it
 *
 * DRY RUN BY DEFAULT. It prints the before/after for every page and changes
 * nothing until --force is passed.
 *
 *   php artisan cms:refresh-page-meta                 # show what would change
 *   php artisan cms:refresh-page-meta --site=winpalack
 *   php artisan cms:refresh-page-meta --force         # apply
 */
class RefreshCmsPageMeta extends Command
{
    protected $signature = 'cms:refresh-page-meta
                            {--site= : Limit to one site slug (default: every active site)}
                            {--force : Actually write the changes (otherwise this is a dry run)}';

    protected $description = 'Refresh the standard legal pages\' meta descriptions with each site\'s positioning';

    public function handle(): int
    {
        $apply = (bool) $this->option('force');
        $slug = $this->option('site');

        $sites = Site::query()
            ->when(is_string($slug) && $slug !== '', fn ($q) => $q->where('slug', $slug))
            ->orderBy('slug')
            ->get();

        if ($sites->isEmpty()) {
            $this->error($slug ? "No site with slug '{$slug}'." : 'No sites found.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('DRY RUN — nothing will be written. Re-run with --force to apply.');
        }

        $updated = 0;
        $skipped = 0;
        $edited = 0;

        foreach ($sites as $site) {
            $this->line('');
            $this->info("── {$site->name} ({$site->slug}) ──");

            if (($site->positioning ?? '') === '') {
                // Without a positioning there is nothing to add, and rewriting
                // the row with an identical string would be pure churn.
                $this->warn('   No positioning set — skipped. Set it on the site in the admin first.');
                continue;
            }

            // Keyed by slug so each page is matched to its own regenerated copy.
            $fresh = collect(LegalPageContent::forBrand($site->name, $site->domain, $site->positioning))
                ->keyBy('slug');
            $previous = collect(LegalPageContent::forBrand($site->name, $site->domain))
                ->keyBy('slug');

            $pages = CmsPage::query()
                ->where('site_id', $site->id)
                ->whereIn('slug', LegalPageContent::slugs())
                ->orderBy('slug')
                ->get();

            foreach ($pages as $page) {
                $new = $fresh[$page->slug]['meta_description'] ?? null;
                $old = $previous[$page->slug]['meta_description'] ?? null;

                if ($new === null || $old === null) {
                    continue;
                }

                if ($page->meta_description === $new) {
                    $skipped++;   // already carries the positioning
                    continue;
                }

                if ($page->meta_description !== $old) {
                    // Someone wrote this by hand. Their words win.
                    $edited++;
                    $this->line("   <fg=yellow>skip</>  {$page->slug}  (hand-edited — left alone)");
                    continue;
                }

                $this->line("   <fg=green>set</>   {$page->slug}");
                $this->line("         <fg=gray>{$page->meta_description}</>");
                $this->line("         {$new}");

                if ($apply) {
                    try {
                        DB::transaction(fn () => $page->update(['meta_description' => $new]));
                    } catch (Throwable $e) {
                        $this->error("         failed: {$e->getMessage()}");

                        return self::FAILURE;
                    }
                }

                $updated++;
            }
        }

        $this->line('');
        $verb = $apply ? 'updated' : 'would update';
        $this->info("{$verb} {$updated} page(s); {$skipped} already current; {$edited} hand-edited and left alone.");

        if (! $apply && $updated > 0) {
            $this->warn('Re-run with --force to write these.');
        }

        return self::SUCCESS;
    }
}
