<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Site;
use App\Models\SitePromotionEmail;
use Illuminate\Database\Seeder;

/**
 * Fills the gaps in an existing promotion email, and touches nothing else.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * Sites attached before a field was added to {@see SitePromotionEmail::defaultsFor()}
 * kept NULL in it forever — the attach-time defaults only ever run once.
 * idevaffiliation is the visible case: no banner, no banner link, no top button
 * and no footer identity, so importing its template into an Email config
 * credential brought across an empty image and an empty button.
 *
 * ── The rule, and it is the whole safety of this seeder ─────────────────────
 *
 * A column is written ONLY when it is currently NULL or an empty string. Copy
 * an editor has written — including a field they deliberately cleared and then
 * saved with other content — is never overwritten, and re-running changes
 * nothing the second time. `active`, the colours and the sender identity are not
 * touched at all: those are decisions, not gaps.
 *
 * A site with no promotion email row at all is SKIPPED rather than created. The
 * row is written when a site is attached; a missing one means something else is
 * wrong, and inventing it here would hide that.
 *
 *   php artisan db:seed --class=PromotionEmailBackfillSeeder --force
 *   php artisan db:seed --class=PromotionEmailBackfillSeeder --force -- --site=idevaffiliation
 */
class PromotionEmailBackfillSeeder extends Seeder
{
    /**
     * Columns this seeder may fill.
     *
     * Deliberately NOT every column in defaultsFor(): the sender address, the
     * colours and `active` are per-site decisions that an empty value can
     * legitimately represent. These eight are content whose absence is always a
     * gap — a message with no banner and no contact line is incomplete whatever
     * anybody intended.
     *
     * @var list<string>
     */
    private const array FILLABLE_GAPS = [
        'hero_image_url',
        'hero_url',
        'top_button_text',
        'heading',
        'intro_text',
        'secondary_text',
        'disclaimer_text',
        'preheader',
        'subject',
        'postal_address',
        'contact_email',
        'copyright_text',
        'unsubscribe_label',
    ];

    public function run(): void
    {
        $only = $this->siteFilter();

        $sites = Site::query()
            ->when($only !== null, fn ($q) => $q->where('slug', $only))
            ->orderBy('id')
            ->get();

        if ($sites->isEmpty()) {
            $this->command?->error('No matching site' . ($only !== null ? " '{$only}'" : '') . '.');

            return;
        }

        $touched = 0;

        foreach ($sites as $site) {
            $promotion = SitePromotionEmail::where('site_id', $site->id)->first();

            if ($promotion === null) {
                $this->command?->line("  - {$site->slug} — no promotion email row, skipped");

                continue;
            }

            $defaults = SitePromotionEmail::defaultsFor($site);
            $fill = [];

            foreach (self::FILLABLE_GAPS as $column) {
                $current = $promotion->{$column};

                if (trim((string) ($current ?? '')) !== '') {
                    continue;
                }

                $value = trim((string) ($defaults[$column] ?? ''));

                if ($value !== '') {
                    $fill[$column] = $value;
                }
            }

            if ($fill === []) {
                $this->command?->line("  = {$site->slug} — nothing missing");

                continue;
            }

            $promotion->forceFill($fill)->save();
            $touched++;

            $this->command?->line("  ~ {$site->slug} — filled: " . implode(', ', array_keys($fill)));
        }

        $this->command?->info("Promotion emails backfilled: {$touched} site(s).");
    }

    /**
     * `--site=<slug>` from the command line, or null for every site.
     *
     * Read from argv rather than a seeder option: `db:seed` passes nothing of
     * its own through, and this is the same shape the other targeted seeders in
     * this directory use.
     */
    private function siteFilter(): ?string
    {
        foreach ($_SERVER['argv'] ?? [] as $argument) {
            if (is_string($argument) && str_starts_with($argument, '--site=')) {
                $slug = trim(substr($argument, 7));

                return $slug === '' ? null : $slug;
            }
        }

        return null;
    }
}
