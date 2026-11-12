<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Jobs\InvalidateCasinoCache;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Writes SEO content — `description`, `meta_title`, `meta_description` — for
 * shared records (casinos, special offers), ONE VERSION PER SITE, from two data
 * files in database/seeders/data/:
 *
 *  - {@see defaultFile()} (record slug → values): the record's own DEFAULT
 *    columns, which the default content site (winpalack) publishes and any site
 *    without its own row falls back to. ONLY EMPTY default fields are filled —
 *    a description, meta title or meta description the record already has is
 *    kept, field by field;
 *  - {@see siteFile()} (site slug → record slug → values): each other site's own
 *    text, written into the record's per-site content table.
 *
 * Production-safe:
 *  - records and sites are matched on `slug`; a missing one is reported and
 *    skipped, never created. A per-site row is written only where the record is
 *    shown on that site, never for the default content site, and `noindex` is
 *    never touched;
 *  - every value is validated before anything is written;
 *  - every value it replaces (empty default fields and existing per-site rows) is
 *    saved first to storage/app/seo-backups/<name>-<time>.json, with the list of
 *    rows it created;
 *  - rows are saved quietly, then each affected site's cache is flushed and its
 *    Next.js app revalidated ONCE. Revalidation is synchronous with a 5-second
 *    timeout per site, so letting the observers run would ping every site once
 *    per row — minutes when a site is down;
 *  - re-running it is a no-op: unchanged rows are not saved again.
 */
abstract class SeoContentSeeder extends Seeder
{
    /**
     * Hard ceilings. The data files are fitted tighter, per site, to what each
     * site appends to them (" | <Brand>" on the title, a signature on some
     * descriptions), so the result stays inside Google's ~60 / ~160 characters.
     */
    private const int MAX_TITLE = 60;
    private const int MAX_DESCRIPTION = 160;

    private const array FIELDS = ['description', 'meta_title', 'meta_description'];

    /**
     * default: record slug → replaced values; sites: site → record → replaced
     * values; created: site → record slugs whose row did not exist before.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $backup = ['default' => [], 'sites' => [], 'created' => []];

    /** @var array<int, true> */
    private array $touchedSites = [];

    /** File name under database/seeders/data/ holding the default content. */
    abstract protected function defaultFile(): string;

    /** File name under database/seeders/data/ holding the per-site content. */
    abstract protected function siteFile(): string;

    /** Base name of the backup file. */
    abstract protected function backupName(): string;

    /**
     * The records named in the data files, keyed by slug, with whatever
     * {@see siteIds()} needs eager-loaded.
     *
     * @param  list<string>  $slugs
     * @return Collection<string, Model>
     */
    abstract protected function records(array $slugs): Collection;

    /**
     * Sites the record is shown on.
     *
     * @return list<int>
     */
    abstract protected function siteIds(Model $record): array;

    /** The per-site content row for this record and site, existing or new. */
    abstract protected function siteRow(Model $record, Site $site, Collection $existing): Model;

    /**
     * Existing per-site rows for one site, keyed by the record's id.
     *
     * @return Collection<int, Model>
     */
    abstract protected function existingRows(Site $site): Collection;

    /**
     * Next.js cache tags to revalidate.
     *
     * @return list<string>
     */
    abstract protected function tags(): array;

    public function run(): void
    {
        $defaults = $this->load($this->defaultFile());
        $perSite = $this->load($this->siteFile(), nested: true);

        $slugs = array_values(array_unique(array_map('strval', [
            ...array_keys($defaults),
            ...array_merge(...array_map('array_keys', array_values($perSite))),
        ])));
        $records = $this->records($slugs);

        foreach (array_diff($slugs, $records->keys()->all()) as $slug) {
            $this->command->warn("No record with slug '{$slug}' in this database — skipped.");
        }

        $defaultChanges = $this->defaultChanges($records, $defaults);
        $siteChanges = $this->siteChanges($records, $perSite);

        if ($defaultChanges === [] && $siteChanges === []) {
            $this->command->info('Every record and site already carries this content. Nothing to write.');

            return;
        }

        $this->command->info('Previous values saved to ' . $this->writeBackup());

        foreach ($defaultChanges as [$record, $values]) {
            $record->fill($values)->saveQuietly();
            foreach ($this->siteIds($record) as $siteId) {
                $this->touchedSites[$siteId] = true;
            }
        }

        foreach ($siteChanges as $row) {
            $row->saveQuietly();
            $this->touchedSites[(int) $row->getAttribute('site_id')] = true;
        }

        $this->command->info(sprintf(
            'Default content written to %d record(s); per-site content written to %d row(s).',
            count($defaultChanges),
            count($siteChanges),
        ));

        // What the record and site-content observers do per save, once for the run.
        InvalidateCasinoCache::dispatch(array_keys($this->touchedSites), $this->tags());
        $this->command->info('Cache flushed and revalidation sent to ' . count($this->touchedSites) . ' site(s).');
    }

    /**
     * @param  Collection<string, Model>  $records
     * @param  array<string, array<string, string>>  $defaults
     * @return list<array{0: Model, 1: array<string, string>}>
     */
    private function defaultChanges(Collection $records, array $defaults): array
    {
        $changes = [];

        foreach ($defaults as $slug => $values) {
            $record = $records->get((string) $slug);
            if ($record === null) {
                continue;
            }

            // Only the EMPTY default fields are filled. A description, meta title
            // or meta description the record already has is what winpalack
            // publishes, and is kept as written.
            $missing = array_filter(
                array_intersect_key($values, array_flip(self::FIELDS)),
                fn (string $value, string $field): bool => $this->isBlank($field, $record->getAttribute($field)),
                ARRAY_FILTER_USE_BOTH,
            );

            if ($missing === []) {
                continue;
            }

            $this->backup['default'][(string) $slug] = $record->only(array_keys($missing));
            $changes[] = [$record, $missing];
        }

        return $changes;
    }

    /**
     * @param  Collection<string, Model>  $records
     * @param  array<string, array<string, array<string, string>>>  $perSite
     * @return list<Model>
     */
    private function siteChanges(Collection $records, array $perSite): array
    {
        $defaultSite = (string) config('site_content.default_site_slug');
        $sites = Site::query()->whereIn('slug', array_keys($perSite))->get()->keyBy('slug');
        $changes = [];

        foreach ($perSite as $siteSlug => $entries) {
            $site = $sites->get($siteSlug);

            if ($site === null) {
                $this->command->warn("No site with slug '{$siteSlug}' in this database — skipped.");

                continue;
            }

            // The default site publishes the record's own columns; it never gets a row.
            if ($site->slug === $defaultSite) {
                continue;
            }

            $existing = $this->existingRows($site);

            foreach ($entries as $slug => $values) {
                $record = $records->get((string) $slug);

                if ($record === null || ! in_array((int) $site->id, $this->siteIds($record), true)) {
                    continue;
                }

                $row = $this->siteRow($record, $site, $existing);

                if ($row->exists && ! $this->differs($row->only(self::FIELDS), $values)) {
                    continue;
                }

                if ($row->exists) {
                    $this->backup['sites'][$site->slug][(string) $slug] = $row->only(self::FIELDS);
                } else {
                    $this->backup['created'][$site->slug][] = (string) $slug;
                }

                $row->fill($values);
                $changes[] = $row;
            }
        }

        return $changes;
    }

    /** No visible text: null, whitespace, or rich text such as `<p><br></p>`. */
    private function isBlank(string $field, mixed $value): bool
    {
        $text = (string) $value;

        if ($field === 'description') {
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        }

        return trim($text) === '';
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, string>  $target
     */
    private function differs(array $current, array $target): bool
    {
        foreach (self::FIELDS as $field) {
            if ((string) ($current[$field] ?? '') !== $target[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * A data file, validated in full before anything is written.
     *
     * @return array<string, mixed>
     */
    private function load(string $file, bool $nested = false): array
    {
        $data = json_decode(File::get(database_path("seeders/data/{$file}")), true, flags: JSON_THROW_ON_ERROR);
        $groups = $nested ? $data : ['' => $data];
        $problems = [];

        foreach ($groups as $group => $rows) {
            foreach ($rows as $slug => $row) {
                $where = trim("{$group} {$slug}");

                foreach (self::FIELDS as $field) {
                    if (! is_string($row[$field] ?? null) || trim($row[$field]) === '') {
                        $problems[] = "{$where}: {$field} is missing";
                    }
                }

                if (mb_strlen((string) ($row['meta_title'] ?? '')) > self::MAX_TITLE) {
                    $problems[] = "{$where}: meta_title is over " . self::MAX_TITLE . ' characters';
                }

                if (mb_strlen((string) ($row['meta_description'] ?? '')) > self::MAX_DESCRIPTION) {
                    $problems[] = "{$where}: meta_description is over " . self::MAX_DESCRIPTION . ' characters';
                }
            }
        }

        if ($problems !== []) {
            throw new RuntimeException("{$file} is invalid — nothing was written:\n" . implode("\n", $problems));
        }

        return $data;
    }

    /** Save what is about to be replaced, so a run can be undone by hand. */
    private function writeBackup(): string
    {
        $path = storage_path('app/seo-backups/' . $this->backupName() . '-' . now()->format('Ymd-His') . '.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($this->backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }
}
