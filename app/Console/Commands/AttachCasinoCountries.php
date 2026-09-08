<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Casino;
use App\Models\Country;
use App\Support\SiteCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-attach casinos to countries.
 *
 * The admin panel has a country picker on each casino, which is the right tool
 * for one casino. It is the wrong tool for "every casino, dozens of countries" —
 * that is this command.
 *
 * ATTACHES, NEVER DETACHES. It adds the pivot rows that are missing and leaves
 * every existing one alone, so running it twice changes nothing and running it
 * with a smaller list never silently removes a market someone set by hand.
 * `--replace` is the explicit opt-out from that rule.
 *
 * A country attachment is a CLAIM: it tells a visitor this operator accepts
 * players from there. It drives which casinos appear on /countries/<slug>. The
 * command therefore refuses to guess — you name the countries, or you pass
 * --all and take responsibility for the blanket claim.
 */
class AttachCasinoCountries extends Command
{
    protected $signature = 'casinos:attach-countries
        {--countries= : Comma-separated ISO codes (DE,AT,CH) or slugs (germany,austria)}
        {--all : Every ACTIVE country instead of a named list}
        {--casino=* : Limit to these casino slugs; omit for all active casinos}
        {--replace : Replace each casino'."'".'s countries instead of adding to them}
        {--dry-run : Show what would change and write nothing}';

    protected $description = 'Attach casinos to countries in bulk (adds by default; never removes unless --replace)';

    public function handle(): int
    {
        $countries = $this->resolveCountries();

        if ($countries === null) {
            return self::FAILURE;
        }

        $casinos = $this->resolveCasinos();

        if ($casinos->isEmpty()) {
            $this->error('No matching active casinos.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $replace = (bool) $this->option('replace');

        $this->line(sprintf(
            '%s %d casino(s) to %d country/countries%s.',
            $dryRun ? 'Would attach' : 'Attaching',
            $casinos->count(),
            $countries->count(),
            $replace ? ' (REPLACING existing attachments)' : ' (adding to existing)',
        ));

        $ids = $countries->pluck('id')->all();
        $added = 0;
        $rows = [];

        foreach ($casinos as $casino) {
            $before = $casino->countries()->pluck('countries.id')->all();
            $after = $replace ? $ids : array_values(array_unique([...$before, ...$ids]));
            $delta = count(array_diff($after, $before));
            $removed = count(array_diff($before, $after));

            $rows[] = [$casino->name, count($before), count($after), $delta, $removed];
            $added += $delta;

            if (! $dryRun) {
                // syncWithoutDetaching for the additive path so a concurrent
                // edit cannot be clobbered; sync only when --replace was asked.
                $replace
                    ? $casino->countries()->sync($after)
                    : $casino->countries()->syncWithoutDetaching($after);
            }
        }

        $this->table(['Casino', 'Before', 'After', 'Added', 'Removed'], $rows);

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');

            return self::SUCCESS;
        }

        $this->flushAffectedSites($casinos);

        $this->info("Done. {$added} attachment(s) added.");
        $this->line('The /countries pages will refresh once each site revalidates.');

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Country>|null */
    private function resolveCountries()
    {
        if ($this->option('all')) {
            return Country::where('active', true)->get(['id', 'name', 'code']);
        }

        $raw = trim((string) $this->option('countries'));

        if ($raw === '') {
            // Deliberately a hard stop. Defaulting to "all" would turn a typo
            // into a site-wide claim that every operator accepts every market.
            $this->error('Pass --countries=DE,AT,CH (ISO codes or slugs), or --all for every active country.');

            return null;
        }

        $tokens = collect(explode(',', $raw))->map(fn ($t) => trim($t))->filter()->values();

        $found = Country::where('active', true)
            ->where(function ($q) use ($tokens): void {
                $q->whereIn('code', $tokens->map(fn ($t) => strtoupper($t))->all())
                    ->orWhereIn('slug', $tokens->map(fn ($t) => strtolower($t))->all());
            })
            ->get(['id', 'name', 'code', 'slug']);

        $missing = $tokens->reject(fn ($t) => $found->contains(
            fn ($c) => strcasecmp($c->code, $t) === 0 || strcasecmp($c->slug, $t) === 0
        ));

        if ($missing->isNotEmpty()) {
            // Refuse the whole run rather than silently attaching a subset —
            // a half-applied market list is worse than none.
            $this->error('Unknown or inactive: ' . $missing->implode(', '));

            return null;
        }

        return $found;
    }

    /** @return \Illuminate\Support\Collection<int, Casino> */
    private function resolveCasinos()
    {
        $query = Casino::where('active', true);
        $slugs = (array) $this->option('casino');

        if ($slugs !== []) {
            $query->whereIn('slug', $slugs);
        }

        return $query->orderBy('id')->get(['id', 'name', 'slug']);
    }

    /**
     * Flush the cache of every site that publishes one of these casinos, and
     * ping it to rebuild. Without this the country pages keep serving their
     * cached copy for up to an hour after the attachment lands.
     */
    private function flushAffectedSites($casinos): void
    {
        $siteIds = DB::table('casino_site')
            ->whereIn('casino_id', $casinos->pluck('id'))
            ->distinct()
            ->pluck('site_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($siteIds === []) {
            return;
        }

        foreach ($siteIds as $siteId) {
            SiteCache::flushSite($siteId);
        }

        \App\Jobs\RevalidateNextJsSites::dispatch(['countries', 'casinos'], $siteIds);

        $this->line('Flushed and revalidated site(s): ' . implode(', ', $siteIds));
    }
}
