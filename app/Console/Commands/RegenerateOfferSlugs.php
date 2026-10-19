<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SpecialOffer;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Rewrite every special-offer slug into the readable form.
 *
 * The old generator appended six random letters to EVERY slug, not only on a
 * collision, and collapsed punctuation to underscores. Combined with the admin's
 * duplicate action it produced `2nd_deposit_bonus_copy_sagmuq`: a tail that
 * means nothing, underscores Google reads as a single word, and the word "copy"
 * published to visitors. {@see SpecialOffer::slugBase()} is the new shape;
 * this command brings the rows that already exist up to it.
 *
 *   php artisan offers:regenerate-slugs --dry-run     # print old → new, change nothing
 *   php artisan offers:regenerate-slugs --force       # apply
 *
 * THE MAP IS ALWAYS WRITTEN, even on a dry run, to
 * storage/app/offer-slugs/<timestamp>.csv. Applying destroys the old slugs, and
 * without that file a decision not to add redirects could never be revisited —
 * nothing else in the system remembers what the URL used to be. It costs a
 * kilobyte.
 *
 * NO REDIRECTS ARE CREATED. That is a deliberate instruction, not an omission:
 * internal links are being updated by hand. Be aware that every old URL will
 * 404 for Google and for anyone holding a link, and the CSV plus the existing
 * admin Redirects screen is what you would use to change that.
 *
 * TWO PASSES, and this is the part that cannot be skipped. `slug` is unique, so
 * assigning B its target while A still holds that value fails — or worse,
 * silently pushes B to `-2`. Every changing row therefore moves to a throwaway
 * slug first, then to its target, inside one transaction.
 *
 * LIVE ROWS ARE NUMBERED FIRST. A soft-deleted offer still occupies its slug,
 * so processing live offers before trashed ones is what keeps the clean slug on
 * the row the public can actually see.
 */
class RegenerateOfferSlugs extends Command
{
    use ConfirmableTrait;

    protected $signature = 'offers:regenerate-slugs
                            {--dry-run : Print old → new and change nothing}
                            {--force : Skip the production confirmation}';

    protected $description = 'Rewrite special-offer slugs as {casino-slug}-{offer-title-slug}';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        /*
         * Live rows first, then trashed, each by id. Ordering by id rather than
         * by title keeps the run reproducible: the same data produces the same
         * `-2` assignments every time, so a dry run is a promise about what the
         * real run will do.
         */
        $offers = SpecialOffer::withTrashed()
            ->with('casino:id,slug')
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('id')
            ->get();

        if ($offers->isEmpty()) {
            $this->info('No special offers.');

            return self::SUCCESS;
        }

        /** @var array<string, true> $taken */
        $taken = [];
        /** @var list<array{id: int, old: string, new: string, title: string, trashed: bool}> $plan */
        $plan = [];

        foreach ($offers as $offer) {
            $base = SpecialOffer::slugBase((string) $offer->title, $offer->casino?->slug);

            // Dedup against the TARGETS already planned, not against the table:
            // every row is being rewritten, so the current contents tell us
            // nothing about what will be free.
            $slug = $base;
            $suffix = 1;

            while (isset($taken[$slug])) {
                $suffix++;
                $slug = $base . '-' . $suffix;
            }

            $taken[$slug] = true;

            $plan[] = [
                'id'      => (int) $offer->id,
                'old'     => (string) $offer->slug,
                'new'     => $slug,
                'title'   => (string) $offer->title,
                'trashed' => $offer->deleted_at !== null,
            ];
        }

        $changing = array_values(array_filter($plan, fn (array $row) => $row['old'] !== $row['new']));

        $this->table(
            ['id', 'title', 'old slug', 'new slug', ''],
            array_map(fn (array $r) => [
                $r['id'],
                mb_strimwidth($r['title'], 0, 38, '…'),
                $r['old'],
                $r['new'],
                $r['trashed'] ? 'trashed' : '',
            ], $plan),
        );

        $path = $this->writeMap($plan, $dryRun);

        $this->newLine();
        $this->line(sprintf('  %d offers, %d slugs change, %d already correct.',
            count($plan), count($changing), count($plan) - count($changing)));
        $this->line('  map: ' . $path);

        if ($dryRun) {
            $this->newLine();
            $this->warn('  DRY RUN — nothing was written to the database.');
            $this->line('  Every old URL above will 404 once applied; no redirects are created.');

            return self::SUCCESS;
        }

        if ($changing === []) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($changing): void {
                // Pass 1 — out of the way. `slug` is unique, so a target cannot
                // be assigned while another row still holds it.
                foreach ($changing as $row) {
                    DB::table('special_offers')
                        ->where('id', $row['id'])
                        ->update(['slug' => '__regen-' . $row['id']]);
                }

                // Pass 2 — the real values, now guaranteed free.
                foreach ($changing as $row) {
                    DB::table('special_offers')
                        ->where('id', $row['id'])
                        ->update(['slug' => $row['new']]);
                }
            });
        } catch (Throwable $e) {
            // The transaction rolled back, so every slug is as it was. Saying
            // so explicitly matters: a half-renamed set would be far worse than
            // none, and the operator needs to know which state they are in.
            $this->error('Rolled back, no slug was changed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('  %d slugs rewritten.', count($changing)));
        $this->line('  Caches are NOT flushed by this command — run the usual');
        $this->line('  revalidation, or save any offer in the admin, so the sites pick it up.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{id: int, old: string, new: string, title: string, trashed: bool}>  $plan
     */
    private function writeMap(array $plan, bool $dryRun): string
    {
        $dir = storage_path('app/offer-slugs');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $file = sprintf('%s/%s%s.csv', $dir, Carbon::now()->format('Y-m-d_His'), $dryRun ? '-dry-run' : '');
        $handle = fopen($file, 'wb');

        fputcsv($handle, ['id', 'title', 'old_slug', 'new_slug', 'trashed']);

        foreach ($plan as $row) {
            fputcsv($handle, [$row['id'], $row['title'], $row['old'], $row['new'], $row['trashed'] ? '1' : '0']);
        }

        fclose($handle);

        return $file;
    }
}
