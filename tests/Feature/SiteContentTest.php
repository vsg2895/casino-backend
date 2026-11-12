<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Casino;
use App\Models\CasinoSiteContent;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\SpecialOfferSiteContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Per-site content for casinos and special offers (Part A).
 *
 * The record's own columns are the default content; a `*_site_contents` row
 * overrides them on one site. Everything here goes through the public and
 * admin HTTP surfaces, because those are the contract the sites and the admin
 * depend on.
 */
class SiteContentTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Saving an override revalidates the Next.js sites.
        Http::fake();
    }

    /** @return array{0: Site, 1: string} */
    private function site(array $attrs = []): array
    {
        return $this->siteWithKey($attrs);
    }

    private function casinoOn(Site ...$sites): Casino
    {
        $casino = Casino::factory()->create([
            'active'           => true,
            'description'      => '<p>Default review.</p>',
            'meta_title'       => 'Default title',
            'meta_description' => 'Default summary',
            'noindex'          => false,
        ]);

        foreach ($sites as $site) {
            $casino->sites()->attach($site->id, ['affiliate_url' => 'https://x.test/go', 'active' => true]);
        }

        return $casino;
    }

    private function offerOf(Casino $casino, array $attrs = []): SpecialOffer
    {
        return SpecialOffer::factory()->create([
            'casino_id'   => $casino->id,
            'title'       => 'Welcome Spins',
            'description' => '<p>Default offer text.</p>',
            'active'      => true,
            'noindex'     => false,
            ...$attrs,
        ]);
    }

    /**
     * Scoped bindings (SiteContentResolver's per-request memo) are reset by
     * the framework between real requests, but not between requests made
     * inside one test — so each simulated request starts from a fresh memo.
     */
    private function freshRequest(): void
    {
        $this->app->forgetScopedInstances();
    }

    /** @return array<string, mixed> */
    private function casinoJson(Site $site, string $key, Casino $casino): array
    {
        $this->freshRequest();

        return $this->getJson($this->publicBase($site) . '/casinos/' . $casino->slug, $this->siteHeaders($key))
            ->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function offerJson(Site $site, string $key, SpecialOffer $offer): array
    {
        $this->freshRequest();

        return $this->getJson($this->publicBase($site) . '/special-offers/' . $offer->slug, $this->siteHeaders($key))
            ->assertOk()->json('data');
    }

    // ── Resolution ──────────────────────────────────────────────────────────

    public function test_a_casino_override_replaces_each_field_on_its_own_site_only(): void
    {
        [$own, $ownKey] = $this->site();
        [$other, $otherKey] = $this->site();
        $casino = $this->casinoOn($own, $other);

        CasinoSiteContent::factory()->create([
            'casino_id' => $casino->id, 'site_id' => $own->id,
            'description' => '<p>Own review.</p>', 'meta_title' => 'Own title', 'meta_description' => 'Own summary',
        ]);

        $mine = $this->casinoJson($own, $ownKey, $casino);
        $this->assertSame('<p>Own review.</p>', $mine['description']);
        $this->assertSame('Own title', $mine['meta_title']);
        $this->assertSame('Own summary', $mine['meta_description']);
        $this->assertSame('Own title', $mine['seo']['title']);

        $theirs = $this->casinoJson($other, $otherKey, $casino);
        $this->assertSame('<p>Default review.</p>', $theirs['description']);
        $this->assertSame('Default title', $theirs['meta_title']);
        $this->assertSame('Default summary', $theirs['meta_description']);
    }

    public function test_an_empty_or_null_override_field_falls_back_to_the_default(): void
    {
        [$site, $key] = $this->site();
        $casino = $this->casinoOn($site);

        // Written raw, past the model's normalisation, to prove the resolver
        // itself never serves an empty value over a filled default.
        DB::table('casino_site_contents')->insert([
            'casino_id' => $casino->id, 'site_id' => $site->id,
            'description' => '<p><br></p>', 'meta_title' => '   ', 'meta_description' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $json = $this->casinoJson($site, $key, $casino);
        $this->assertSame('<p>Default review.</p>', $json['description']);
        $this->assertSame('Default title', $json['meta_title']);
        $this->assertSame('Default summary', $json['meta_description']);
    }

    public function test_offer_meta_falls_back_to_title_and_casino_then_a_description_snippet(): void
    {
        [$site, $key] = $this->site();
        $casino = $this->casinoOn($site);
        $long = '<p>' . str_repeat('Generous spins every single week ', 10) . '</p>';
        $offer = $this->offerOf($casino, ['description' => $long, 'meta_title' => null, 'meta_description' => null]);

        $json = $this->offerJson($site, $key, $offer);

        $this->assertSame('Welcome Spins – ' . $casino->name, $json['meta_title']);
        $this->assertStringStartsWith('Generous spins every single week', $json['meta_description']);
        $this->assertStringEndsWith('…', $json['meta_description']);
        $this->assertLessThanOrEqual(156, mb_strlen($json['meta_description']));
        $this->assertStringNotContainsString('<p>', $json['meta_description']);
        // The generated fallback never reaches the seo block (D3).
        $this->assertNull($json['seo']['title']);
    }

    public function test_offer_override_and_global_meta_resolve_in_order(): void
    {
        [$own, $ownKey] = $this->site();
        [$other, $otherKey] = $this->site();
        $casino = $this->casinoOn($own, $other);
        $offer = $this->offerOf($casino, ['meta_title' => 'Global offer title', 'meta_description' => 'Global offer summary']);

        SpecialOfferSiteContent::factory()->create([
            'special_offer_id' => $offer->id, 'site_id' => $own->id,
            'description' => '<p>Own offer text.</p>', 'meta_title' => 'Own offer title', 'meta_description' => null,
        ]);

        $mine = $this->offerJson($own, $ownKey, $offer);
        $this->assertSame('<p>Own offer text.</p>', $mine['description']);
        $this->assertSame('Own offer title', $mine['meta_title']);
        $this->assertSame('Global offer summary', $mine['meta_description']);

        $theirs = $this->offerJson($other, $otherKey, $offer);
        $this->assertSame('<p>Default offer text.</p>', $theirs['description']);
        $this->assertSame('Global offer title', $theirs['meta_title']);
    }

    // ── noindex ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: bool, 1: ?bool, 2: bool, 3: bool, 4: bool}>
     *   global noindex, site value, flag on, site has own description, expected
     */
    public static function noindexMatrix(): array
    {
        return [
            'global wins over explicit index'      => [true, false, false, true, true],
            'global wins over everything'          => [true, null, true, false, true],
            'site noindex'                         => [false, true, false, true, true],
            'site index beats the flag'            => [false, false, true, false, false],
            'inherit, flag off'                    => [false, null, false, false, false],
            'inherit, flag on, no own text'        => [false, null, true, false, true],
            'inherit, flag on, own text'           => [false, null, true, true, false],
        ];
    }

    #[DataProvider('noindexMatrix')]
    public function test_noindex_resolution(bool $global, ?bool $siteValue, bool $flag, bool $ownText, bool $expected): void
    {
        [$site, $key] = $this->site(['noindex_without_override' => $flag]);
        $casino = $this->casinoOn($site);
        $casino->update(['noindex' => $global]);
        $offer = $this->offerOf($casino, ['noindex' => $global]);

        if ($siteValue !== null || $ownText) {
            $row = ['site_id' => $site->id, 'noindex' => $siteValue, 'description' => $ownText ? '<p>Own.</p>' : null, 'meta_title' => $ownText ? null : 'T'];
            CasinoSiteContent::factory()->create(['casino_id' => $casino->id, ...$row]);
            SpecialOfferSiteContent::factory()->create(['special_offer_id' => $offer->id, ...$row]);
        }

        $this->assertSame($expected, $this->casinoJson($site, $key, $casino)['seo']['noindex']);
        $offerJson = $this->offerJson($site, $key, $offer);
        $this->assertSame($expected, $offerJson['seo']['noindex']);
        $this->assertSame(! $expected, $offerJson['is_indexable']);
    }

    public function test_the_sitemap_leaves_out_what_the_site_resolves_as_noindex(): void
    {
        [$site, $key] = $this->site(['noindex_without_override' => true]);
        $withText = $this->casinoOn($site);
        $without = $this->casinoOn($site);
        $offerWithText = $this->offerOf($withText);
        $this->offerOf($without);

        CasinoSiteContent::factory()->create(['casino_id' => $withText->id, 'site_id' => $site->id, 'description' => '<p>Own.</p>']);
        $row = SpecialOfferSiteContent::factory()->create(['special_offer_id' => $offerWithText->id, 'site_id' => $site->id, 'description' => '<p>Own.</p>']);
        // The override is newer than the offer, so it dates the entry.
        $row->forceFill(['updated_at' => now()->addDay()])->saveQuietly();

        $this->freshRequest();
        $data = $this->getJson($this->publicBase($site) . '/sitemap', $this->siteHeaders($key))->assertOk()->json('data');

        $this->assertSame([$withText->slug], array_column($data['casinos'], 'slug'));
        $this->assertSame([$offerWithText->slug], array_column($data['offers'], 'slug'));
        $this->assertSame($row->updated_at->toISOString(), $data['offers'][0]['updated_at']);
    }

    // ── Contract ────────────────────────────────────────────────────────────

    public function test_a_site_without_overrides_serves_the_default_columns_unchanged(): void
    {
        [$site, $key] = $this->site();
        $casino = $this->casinoOn($site);
        $casino->forceFill(['meta_title' => '  Spaced title ', 'meta_description' => ''])->saveQuietly();
        $offer = $this->offerOf($casino);

        $json = $this->casinoJson($site, $key, $casino);
        // Byte-for-byte the stored values — not trimmed, not nulled.
        $this->assertSame('  Spaced title ', $json['meta_title']);
        $this->assertSame('', $json['meta_description']);
        $this->assertSame($casino->fresh()->updated_at->toISOString(), $json['updated_at']);

        $this->freshRequest();
        $list = $this->getJson($this->publicBase($site) . '/special-offers', $this->siteHeaders($key))->assertOk()->json('data.0');
        $this->assertSame('<p>Default offer text.</p>', $list['description']);
        $this->assertTrue($list['is_indexable']);
        $this->assertSame($offer->fresh()->updated_at->toISOString(), $list['updated_at']);
    }

    public function test_response_keys_are_identical_on_every_site_and_only_values_differ(): void
    {
        [$own, $ownKey] = $this->site();
        [$other, $otherKey] = $this->site();
        $casino = $this->casinoOn($own, $other);
        $offer = $this->offerOf($casino);
        CasinoSiteContent::factory()->create(['casino_id' => $casino->id, 'site_id' => $own->id, 'description' => '<p>Own.</p>']);
        SpecialOfferSiteContent::factory()->create(['special_offer_id' => $offer->id, 'site_id' => $own->id, 'meta_title' => 'Own']);

        $keys = static fn (array $json): array => array_keys($json);

        $this->assertSame($keys($this->casinoJson($own, $ownKey, $casino)), $keys($this->casinoJson($other, $otherKey, $casino)));
        $mine = $this->offerJson($own, $ownKey, $offer);
        $theirs = $this->offerJson($other, $otherKey, $offer);
        $this->assertSame($keys($mine), $keys($theirs));
        $this->assertContains('meta_title', $keys($mine));
        $this->assertContains('meta_description', $keys($mine));
        $this->assertNotSame($mine['meta_title'], $theirs['meta_title']);
    }

    public function test_a_listing_loads_overrides_in_constant_queries(): void
    {
        [$site, $key] = $this->site();
        $count = function (int $casinos) use ($site, $key): int {
            for ($i = 0; $i < $casinos; $i++) {
                $casino = $this->casinoOn($site);
                $this->offerOf($casino);
                CasinoSiteContent::factory()->create(['casino_id' => $casino->id, 'site_id' => $site->id]);
            }

            cache()->flush();
            $this->freshRequest();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($this->publicBase($site) . '/casinos', $this->siteHeaders($key))->assertOk();
            $this->getJson($this->publicBase($site) . '/special-offers', $this->siteHeaders($key))->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = $count(2);
        $large = $count(6);

        $this->assertSame($small, $large, 'Listing queries grew with the number of casinos — an N+1.');
    }

    // ── Admin ───────────────────────────────────────────────────────────────

    public function test_saving_one_site_touches_only_its_own_row(): void
    {
        [$a] = $this->site();
        [$b] = $this->site();
        $casino = $this->casinoOn($a, $b);
        $theirs = CasinoSiteContent::factory()->create(['casino_id' => $casino->id, 'site_id' => $b->id, 'description' => '<p>B text.</p>']);
        $this->actingAsAdmin();

        $this->putJson("/api/v1/admin/casinos/{$casino->id}/site-contents/{$a->id}", [
            'description' => '<p>A text.</p>', 'meta_title' => 'A title', 'meta_description' => null, 'noindex' => true,
        ])->assertOk()->assertJsonPath('data.content.meta_title', 'A title');

        $this->assertDatabaseHas('casino_site_contents', ['casino_id' => $casino->id, 'site_id' => $a->id, 'meta_title' => 'A title', 'noindex' => true]);
        $this->assertSame('<p>B text.</p>', $theirs->fresh()->description);
        $fresh = $casino->fresh();
        $this->assertSame('<p>Default review.</p>', $fresh->description);
        $this->assertSame('Default title', $fresh->meta_title);
    }

    public function test_an_all_empty_inherit_save_or_a_copy_of_the_default_deletes_the_row(): void
    {
        [$site] = $this->site();
        $casino = $this->casinoOn($site);
        $offer = $this->offerOf($casino);
        CasinoSiteContent::factory()->create(['casino_id' => $casino->id, 'site_id' => $site->id]);
        SpecialOfferSiteContent::factory()->create(['special_offer_id' => $offer->id, 'site_id' => $site->id]);
        $this->actingAsAdmin();

        $this->putJson("/api/v1/admin/casinos/{$casino->id}/site-contents/{$site->id}", [
            'description' => '<p><br></p>', 'meta_title' => '', 'meta_description' => null, 'noindex' => null,
        ])->assertOk()->assertJsonPath('data.content', null);

        // Pasting the default back is not this site's own text either.
        $this->putJson("/api/v1/admin/special-offers/{$offer->id}/site-contents/{$site->id}", [
            'description' => '<p>Default offer text.</p>', 'meta_title' => null, 'meta_description' => null, 'noindex' => null,
        ])->assertOk()->assertJsonPath('data.content', null);

        $this->assertDatabaseCount('casino_site_contents', 0);
        $this->assertDatabaseCount('special_offer_site_contents', 0);
    }

    public function test_the_overview_returns_the_default_and_each_sites_saved_values(): void
    {
        [$site] = $this->site(['name' => 'Alpha']);
        $casino = $this->casinoOn($site);
        CasinoSiteContent::factory()->create(['casino_id' => $casino->id, 'site_id' => $site->id, 'meta_title' => 'Alpha title', 'noindex' => false]);
        $this->actingAsAdmin();

        $this->getJson("/api/v1/admin/casinos/{$casino->id}/site-contents")
            ->assertOk()
            ->assertJsonPath('data.default.meta_title', 'Default title')
            ->assertJsonPath('data.sites.0.slug', $site->slug)
            ->assertJsonPath('data.sites.0.attached', true)
            ->assertJsonPath('data.sites.0.content.meta_title', 'Alpha title')
            ->assertJsonPath('data.sites.0.content.noindex', false);
    }

    public function test_the_default_site_cannot_get_an_override(): void
    {
        [$default] = $this->site(['slug' => config('site_content.default_site_slug')]);
        $casino = $this->casinoOn($default);
        $this->actingAsAdmin();

        $this->putJson("/api/v1/admin/casinos/{$casino->id}/site-contents/{$default->id}", ['meta_title' => 'Nope'])
            ->assertStatus(422);
        $this->assertDatabaseCount('casino_site_contents', 0);
        $this->getJson("/api/v1/admin/casinos/{$casino->id}/site-contents")
            ->assertOk()->assertJsonPath('data.default_site.slug', $default->slug)->assertJsonCount(0, 'data.sites');
    }

    public function test_coverage_and_missing_filter_agree(): void
    {
        [$a] = $this->site();
        [$b] = $this->site();
        $covered = $this->casinoOn($a, $b);
        $missing = $this->casinoOn($a);
        CasinoSiteContent::factory()->create(['casino_id' => $covered->id, 'site_id' => $a->id]);
        $this->actingAsAdmin();

        $rows = collect($this->getJson('/api/v1/admin/content-coverage?type=casino')->assertOk()->json('data.rows'))->keyBy('id');
        $this->assertSame('custom', $rows[$covered->id]['cells'][$a->slug]);
        $this->assertSame('default', $rows[$covered->id]['cells'][$b->slug]);
        $this->assertSame('not_attached', $rows[$missing->id]['cells'][$b->slug]);

        $ids = array_column($this->getJson("/api/v1/admin/casinos?missing_for_site={$a->id}")->assertOk()->json('data'), 'id');
        $this->assertSame([$missing->id], $ids);
        $this->getJson("/api/v1/admin/casinos/count?missing_for_site={$a->id}")->assertOk()->assertJsonPath('total', 1);
    }

    public function test_saving_an_override_flushes_only_that_sites_cache(): void
    {
        [$own, $ownKey] = $this->site();
        [$other, $otherKey] = $this->site();
        $casino = $this->casinoOn($own, $other);
        $this->actingAsAdmin();

        // Warm both caches.
        $this->casinoJson($own, $ownKey, $casino);
        $this->casinoJson($other, $otherKey, $casino);

        $this->putJson("/api/v1/admin/casinos/{$casino->id}/site-contents/{$own->id}", ['description' => '<p>Fresh.</p>'])->assertOk();

        $this->assertSame('<p>Fresh.</p>', $this->casinoJson($own, $ownKey, $casino)['description']);
        $this->assertSame('<p>Default review.</p>', $this->casinoJson($other, $otherKey, $casino)['description']);
    }

    // ── Migrations ──────────────────────────────────────────────────────────

    public function test_the_part_a_migrations_roll_back_and_reapply_and_move_existing_site_texts(): void
    {
        [$site] = $this->site();
        $casino = $this->casinoOn($site);

        $this->artisan('migrate:rollback', ['--step' => 5])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('casino_site_contents'));
        $this->assertFalse(Schema::hasColumn('special_offers', 'meta_title'));

        // A text written through the old per-site column…
        DB::table('casino_site')->where('casino_id', $casino->id)->update(['description' => '<p>Legacy own text.</p>']);

        $this->artisan('migrate')->assertSuccessful();

        // …arrives as this site's override, and nothing else gets a row.
        $this->assertDatabaseHas('casino_site_contents', ['casino_id' => $casino->id, 'site_id' => $site->id, 'description' => '<p>Legacy own text.</p>']);
        $this->assertDatabaseCount('casino_site_contents', 1);
        $this->assertFalse((bool) $site->fresh()->noindex_without_override);
    }
}
