<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Split the eleven standard CMS pages into the six that stay out of the index
 * and the five that belong in it.
 *
 * WHY THEY WERE ALL NOINDEX. The front end decided this in code: any slug in
 * `LEGAL_PAGES` got `noindex, follow`. The reasoning was sound — all eleven are
 * generated from one template in App\Support\LegalPageContent with only the
 * brand and the URLs substituted, so indexing them would put eight
 * near-identical privacy policies into the index competing with each other.
 *
 * WHY THAT IS WRONG FOR FIVE OF THEM. Search Console reported /about,
 * /editorial-policy, /responsible-gambling and /affiliate-disclosure as
 * "Excluded by noindex". For a gambling affiliate those are exactly the pages
 * Google's quality guidance looks for — who runs this site, how it is funded,
 * how operators are assessed. Suppressing them hides the trust signals the
 * whole domain is judged on.
 *
 * SO THE DECISION MOVES INTO THE DATA. `noindex` already exists on this table
 * and is already editable through the admin requests; nothing read it. This
 * migration gives it the right starting value per slug, and the front end now
 * reads the flag instead of a hardcoded list — so a page becomes indexable the
 * moment someone rewrites it for that site, with no deploy.
 *
 * THE SIX LEGAL PAGES KEEP noindex, and should keep it until they are genuinely
 * rewritten per site. The five trust pages are set indexable, which is a
 * promise the CONTENT has to keep: while they are still the untouched template,
 * eight domains publish the same words. Rewriting them per site is editorial
 * work this migration cannot do, and the flag is where to switch one back off
 * in the meantime.
 *
 * Only rows still at the DEFAULT are touched. An operator who has already
 * decided for a page keeps their decision — that is the whole point of moving
 * this out of code.
 */
return new class extends Migration
{
    /**
     * Templated across every site in the network, and not pages anyone searches
     * for by name. These stay out.
     */
    private const array LEGAL = [
        'privacy-policy',
        'terms',
        'cookie-policy',
        'disclaimer',
        'aml-policy',
        'kyc-policy',
    ];

    /**
     * The pages that answer "who is behind this site and how does it make
     * money". Indexable.
     */
    private const array TRUST = [
        'about',
        'contact',
        'responsible-gambling',
        'affiliate-disclosure',
        'editorial-policy',
    ];

    public function up(): void
    {
        // `noindex` defaulted to false for every row, so "false" is
        // indistinguishable from "an operator chose indexable". That is
        // acceptable here precisely because nothing ever read the column: no
        // decision can have been made through it yet.
        DB::table('cms_pages')
            ->whereIn('slug', self::LEGAL)
            ->update(['noindex' => true]);

        DB::table('cms_pages')
            ->whereIn('slug', self::TRUST)
            ->update(['noindex' => false]);
    }

    public function down(): void
    {
        // Back to what the code did: all eleven out of the index. NOT "all
        // false" — that would leave the network publishing eight copies of the
        // same privacy policy, which is the state this schema change exists to
        // avoid, and a rollback must never be the more dangerous direction.
        DB::table('cms_pages')
            ->whereIn('slug', [...self::LEGAL, ...self::TRUST])
            ->update(['noindex' => true]);
    }
};
