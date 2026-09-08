<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Country;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Downloads a circular flag for every country and points `image_path` at it.
 *
 * A command rather than a migration, because migrations move rows and these are
 * files. It is safe to re-run: by default it skips any country that already has
 * an `image_path`, so an admin's own upload is never replaced by a generic flag.
 * `--force` overwrites everything, `--only=` narrows it to specific slugs.
 *
 * Primary source: HatScripts/circle-flags (MIT). The artwork is 1:1 and already
 * circular, which is what the design calls for — a 4:3 flag cropped to a circle
 * loses the edges of designs like Malta's or Nepal's.
 *
 * A handful of entries are not countries and have no ISO code, so that set does
 * not carry them. Those come from {@see EXTERNAL} as ordinary rectangular SVGs
 * and are cropped to a circle here by {@see circleWrap()}. It is only safe
 * because each one is a centred emblem on a plain field, where a centre crop
 * loses nothing.
 *
 * Files land on the `public` disk beside the casino images, so they are served
 * through the same /storage mount and deploy the same way.
 */
class FetchCountryFlags extends Command
{
    protected $signature = 'countries:fetch-flags
                            {--force : Re-download and overwrite flags that are already set}
                            {--only= : Comma-separated country slugs to limit the run to}
                            {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Download circular flag images for countries and set their image_path';

    /** Where the files go on the `public` disk. */
    private const string DIRECTORY = 'flags';

    private const string SOURCE = 'https://hatscripts.github.io/circle-flags/flags/';

    /**
     * Countries whose artwork is not filed under their ISO code.
     *
     * Keyed by country slug. `Europe` is an EU-wide card rather than a country,
     * so it carries the union's flag; the source files that under a name, not a
     * code. Anything else with no ISO code is reported and skipped rather than
     * guessed at — a wrong flag is worse than none.
     *
     * @var array<string, string>
     */
    private const array OVERRIDES = [
        'europe' => 'european_union',
    ];

    /**
     * Entries fetched from elsewhere as RECTANGULAR SVGs, then cropped to a
     * circle locally.
     *
     * Keyed by country slug. `Arab` is the Arab League, which has no ISO code and
     * is absent from the circular set. The file is public domain on Wikimedia
     * Commons; Commons additionally flags it as an "insignia", which restricts
     * passing it off as an official emblem — not its use as an identifying icon,
     * which is what it is doing here.
     *
     * @var array<string, string>
     */
    private const array EXTERNAL = [
        'arab' => 'https://upload.wikimedia.org/wikipedia/commons/2/2b/Flag_of_the_Arab_League.svg',
    ];

    /**
     * Wikimedia refuses requests without a descriptive User-Agent, and the
     * circle-flags host is happy with one, so it is sent to both.
     */
    private const string USER_AGENT = 'casino-platform/1.0 (country flag sync; admin tooling)';

    public function handle(): int
    {
        $query = Country::query()->orderBy('id');

        if (($only = trim((string) $this->option('only'))) !== '') {
            $slugs = array_filter(array_map('trim', explode(',', $only)));
            $query->whereIn('slug', $slugs);
        }

        if (! $this->option('force')) {
            $query->whereNull('image_path');
        }

        /** @var list<Country> $countries */
        $countries = $query->get()->all();

        if ($countries === []) {
            $this->info('Nothing to do — every country already has a flag. Use --force to re-download.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf('%s %d countries…', $dryRun ? 'Would fetch' : 'Fetching', count($countries)));

        $downloaded = 0;
        $skipped = [];
        $failed = [];

        foreach ($countries as $country) {
            // An external entry is rectangular and needs cropping; everything
            // else comes from the circular set ready to use.
            $external = self::EXTERNAL[$country->slug] ?? null;
            $source = $external ?? $this->sourceFor($country);

            if ($source === null) {
                $skipped[] = $country->name;

                continue;
            }

            $url = $external ?? (self::SOURCE . $source . '.svg');
            $path = self::DIRECTORY . '/' . $country->slug . '.svg';

            if ($dryRun) {
                $this->line("  would fetch {$url} → {$path}");
                $downloaded++;

                continue;
            }

            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                    ->timeout(30)
                    ->retry(2, 500)
                    ->get($url);

                // A 404 returns an HTML error page, which would otherwise be
                // written to disk as a .svg that renders as nothing.
                if (! $response->successful() || ! str_contains($response->body(), '<svg')) {
                    $failed[] = $country->name . ' (' . $response->status() . ')';

                    continue;
                }

                $body = $external === null ? $response->body() : $this->circleWrap($response->body());

                Storage::disk('public')->put($path, $body);
                $country->forceFill(['image_path' => $path])->save();

                $downloaded++;
                $this->line("  <fg=green>✓</> {$country->name} → {$path}");
            } catch (Throwable $e) {
                $failed[] = $country->name . ' (' . $e->getMessage() . ')';
            }
        }

        $this->newLine();
        $this->info(sprintf('%d flag%s %s.', $downloaded, $downloaded === 1 ? '' : 's', $dryRun ? 'would be written' : 'written'));

        if ($skipped !== []) {
            $this->warn('No ISO code, skipped: ' . implode(', ', $skipped));
        }

        if ($failed !== []) {
            $this->error('Failed: ' . implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Crop a rectangular SVG to a 512x512 circle, matching the circular set.
     *
     * The source is nested as a child <svg> with
     * `preserveAspectRatio="xMidYMid slice"` — the SVG equivalent of
     * `object-fit: cover` — rather than by computing a transform. That keeps the
     * source's own viewBox and coordinate system intact, so nothing has to be
     * re-scaled by hand and a source with an unusual viewBox still lands centred.
     *
     * The mask id is prefixed to avoid colliding with ids inside the source,
     * which frequently ships CorelDRAW/Inkscape ids of its own.
     */
    private function circleWrap(string $svg): string
    {
        // Drop anything before the root element — XML declarations, comments,
        // DOCTYPEs — none of which may appear inside another element.
        $start = strpos($svg, '<svg');

        if ($start === false) {
            return $svg;
        }

        $svg = substr($svg, $start);

        // RDF metadata is typically half the file and renders nothing.
        $svg = (string) preg_replace('/<metadata\b.*?<\/metadata>/is', '', $svg);

        // Force the nested viewport to fill the circle and cover it.
        //
        // The source's own width/height/preserveAspectRatio are REMOVED first.
        // Prepending the new ones alone leaves the tag carrying two `width`
        // attributes, which is not well-formed XML — and a standalone .svg is
        // parsed as XML, so the browser renders nothing at all rather than
        // ignoring the duplicate the way it would in HTML.
        $svg = (string) preg_replace_callback(
            '/^<svg\b[^>]*>/i',
            static function (array $match): string {
                $tag = (string) preg_replace(
                    '/\s(?:width|height|preserveAspectRatio)\s*=\s*("[^"]*"|\'[^\']*\')/i',
                    '',
                    $match[0],
                );

                return (string) preg_replace(
                    '/^<svg\b/i',
                    '<svg width="512" height="512" preserveAspectRatio="xMidYMid slice"',
                    $tag,
                    1,
                );
            },
            $svg,
            1,
        );

        return '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">'
            . '<mask id="flagCircleMask"><circle cx="256" cy="256" r="256" fill="#fff"/></mask>'
            . '<g mask="url(#flagCircleMask)">' . $svg . '</g></svg>';
    }

    /** The source file name for a country, or null when there is nothing to fetch. */
    private function sourceFor(Country $country): ?string
    {
        if (isset(self::OVERRIDES[$country->slug])) {
            return self::OVERRIDES[$country->slug];
        }

        $code = strtolower(trim((string) $country->code));

        return $code === '' ? null : $code;
    }
}
