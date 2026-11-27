<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ResolveVisitGeoInfo;
use App\Models\BonusCategory;
use App\Models\Casino;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\SpecialOfferVisit;
use App\Models\SpecialOfferVisitIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SpecialOfferVisitTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = Site::generateApiKey();

        $this->site = Site::create([
            'name'    => 'Tracking Site',
            'slug'    => 'tracking-site',
            'domain'  => 'tracking.test',
            'api_key' => Hash::make($this->key),
            'active'  => true,
        ]);
    }

    private function offer(?string $categorySlug = null): SpecialOffer
    {
        $casino = Casino::factory()->create();
        $casino->sites()->attach($this->site->id, ['active' => true, 'affiliate_url' => 'https://example.com/go']);

        $categoryId = null;

        if ($categorySlug !== null) {
            /*
             * FIND BEFORE CREATE. A migration already seeds the standard bonus
             * categories, `special-offers` among them, and BonusCategory uses
             * spatie/laravel-sluggable — so creating a second "Special Offers"
             * here does not fail, it silently gets the slug `special-offers-1`
             * and stops being the category the feature looks for. The first
             * run of this suite failed exactly that way.
             */
            $categoryId = BonusCategory::firstWhere('slug', $categorySlug)?->id;

            if ($categoryId === null) {
                $category = new BonusCategory([
                    'name'     => ucwords(str_replace('-', ' ', $categorySlug)),
                    'position' => 0,
                    'active'   => true,
                ]);
                $category->save();
                $categoryId = $category->id;

                // Pin the slug: the generator derives it from the name, and
                // this test needs the exact value it asked for.
                $category->forceFill(['slug' => $categorySlug])->save();
            }
        }

        return SpecialOffer::factory()->create([
            'casino_id'         => $casino->id,
            'bonus_category_id' => $categoryId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function track(array $payload, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            "/api/v1/public/sites/{$this->site->slug}/special-offer-visits",
            $payload,
            array_merge(['X-Site-Key' => $this->key], $headers)
        );
    }

    /** @return array<string, mixed> */
    private function payload(SpecialOffer $offer, array $extra = []): array
    {
        return array_merge([
            'visit_uuid'       => (string) Str::uuid(),
            'special_offer_id' => $offer->id,
            'page_url'         => 'https://tracking.test/special-offers/x',
            'consent'          => 'all',
        ], $extra);
    }

    // ── what counts as a special offer ──────────────────────────────────────

    public function test_offer_in_the_special_offers_category_is_tracked(): void
    {
        $offer = $this->offer('special-offers');

        $this->track($this->payload($offer))->assertStatus(201);

        $this->assertDatabaseCount('special_offer_visits', 1);
    }

    public function test_uncategorised_offer_is_tracked(): void
    {
        // The admin promises this in its own helper text: an uncategorised
        // offer still appears on the Special Offers page.
        $offer = $this->offer(null);

        $this->track($this->payload($offer))->assertStatus(201);

        $this->assertDatabaseCount('special_offer_visits', 1);
        $this->assertTrue($offer->fresh()->isSpecialOffer());
    }

    public function test_offer_in_another_category_is_not_tracked(): void
    {
        $offer = $this->offer('free-spins');

        $this->track($this->payload($offer))->assertNoContent();

        $this->assertDatabaseCount('special_offer_visits', 0);
        $this->assertFalse($offer->fresh()->isSpecialOffer());
    }

    public function test_scope_matches_the_single_model_check(): void
    {
        $special = $this->offer('special-offers');
        $uncategorised = $this->offer(null);
        $other = $this->offer('free-spins');

        $ids = SpecialOffer::specialOffers()->pluck('id')->all();

        $this->assertContains($special->id, $ids);
        $this->assertContains($uncategorised->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    // ── header extraction and proxy trust ───────────────────────────────────

    public function test_untrusted_caller_cannot_forge_its_own_address(): void
    {
        $offer = $this->offer('special-offers');

        // 8.8.8.8 is not one of our proxies, so its forwarding headers are
        // evidence of nothing.
        $this->track(
            $this->payload($offer),
            ['REMOTE_ADDR' => '8.8.8.8', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4']
        )->assertStatus(201);

        $visit = SpecialOfferVisit::firstOrFail();

        $this->assertSame('8.8.8.8', $visit->primary_ip);
        $this->assertDatabaseMissing('special_offer_visit_ips', ['ip' => '1.2.3.4']);
    }

    public function test_trusted_proxy_header_is_believed(): void
    {
        $offer = $this->offer('special-offers');

        // 172.68.1.1 is inside Cloudflare's published 172.64.0.0/13.
        $this->track(
            $this->payload($offer),
            ['REMOTE_ADDR' => '172.68.1.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.7']
        )->assertStatus(201);

        $this->assertSame('203.0.113.7', SpecialOfferVisit::firstOrFail()->primary_ip);
    }

    public function test_every_x_forwarded_for_entry_is_stored_separately(): void
    {
        $offer = $this->offer('special-offers');

        $this->track(
            $this->payload($offer),
            ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 70.41.3.18, 150.172.238.178']
        )->assertStatus(201);

        $visit = SpecialOfferVisit::firstOrFail();

        $this->assertSame(3, $visit->ips()->where('type', 'x_forwarded_for')->count());
        $this->assertSame('203.0.113.9', $visit->primary_ip);
    }

    public function test_ipv6_is_recorded_with_its_family(): void
    {
        $offer = $this->offer('special-offers');

        $this->track(
            $this->payload($offer),
            ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '2001:db8::dead:beef']
        )->assertStatus(201);

        $row = SpecialOfferVisitIp::where('ip', '2001:db8::dead:beef')->firstOrFail();

        $this->assertSame(6, $row->ip_version);
        $this->assertFalse($row->is_private);
        $this->assertSame('server_seen', $row->source);
    }

    public function test_cloudflare_country_is_used_as_an_immediate_hint(): void
    {
        $offer = $this->offer('special-offers');

        $this->track($this->payload($offer), [
            'REMOTE_ADDR'           => '172.68.1.1',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.7',
            'HTTP_CF_IPCOUNTRY'     => 'gb',
        ])->assertStatus(201);

        $this->assertSame('GB', SpecialOfferVisitIp::where('ip', '203.0.113.7')->firstOrFail()->country_code);
    }

    public function test_site_reported_visitor_ip_beats_the_cloudflare_header(): void
    {
        $offer = $this->offer('special-offers');

        /*
         * THE REGRESSION THIS PINS, found in production on 2026-11-27.
         *
         * The site's Next.js server proxies the browser's beacon to this API
         * at its PUBLIC hostname, which is behind Cloudflare — so Cloudflare
         * rewrites CF-Connecting-IP with the calling server's own address on
         * the way in. Every visitor was recorded as the hosting provider's IP
         * in the datacentre's city. X-Visitor-IP is the header nothing in that
         * path claims, so it has to outrank the one that gets rewritten.
         */
        $this->track($this->payload($offer), [
            'REMOTE_ADDR'           => '172.68.1.1',       // Cloudflare edge
            'HTTP_CF_CONNECTING_IP' => '147.93.132.205',   // the Next.js host
            'HTTP_X_VISITOR_IP'     => '203.0.113.77',     // the real visitor
        ])->assertStatus(201);

        $visit = SpecialOfferVisit::firstOrFail();

        $this->assertSame('203.0.113.77', $visit->primary_ip);
        $this->assertSame(
            'site_reported',
            SpecialOfferVisitIp::where('ip', '203.0.113.77')->firstOrFail()->type
        );
    }

    public function test_site_reported_header_is_ignored_from_an_untrusted_caller(): void
    {
        $offer = $this->offer('special-offers');

        // Not one of our proxies, so nothing it claims about the caller counts.
        $this->track($this->payload($offer), [
            'REMOTE_ADDR'       => '8.8.8.8',
            'HTTP_X_VISITOR_IP' => '203.0.113.77',
        ])->assertStatus(201);

        $this->assertSame('8.8.8.8', SpecialOfferVisit::firstOrFail()->primary_ip);
        $this->assertDatabaseMissing('special_offer_visit_ips', ['ip' => '203.0.113.77']);
    }

    // ── bots and dedup ──────────────────────────────────────────────────────

    public function test_bots_are_not_recorded(): void
    {
        $offer = $this->offer('special-offers');

        $this->track($this->payload($offer), ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])
            ->assertNoContent();

        $this->assertDatabaseCount('special_offer_visits', 0);
    }

    public function test_repeat_visit_within_the_window_reuses_the_row(): void
    {
        $offer = $this->offer('special-offers');
        $headers = ['REMOTE_ADDR' => '203.0.113.50'];

        $this->track($this->payload($offer), $headers)->assertStatus(201);
        $this->track($this->payload($offer), $headers)->assertStatus(201);

        $this->assertDatabaseCount('special_offer_visits', 1);
    }

    public function test_a_different_address_is_a_different_visit(): void
    {
        $offer = $this->offer('special-offers');

        $this->track($this->payload($offer), ['REMOTE_ADDR' => '203.0.113.50'])->assertStatus(201);
        $this->track($this->payload($offer), ['REMOTE_ADDR' => '203.0.113.51'])->assertStatus(201);

        $this->assertDatabaseCount('special_offer_visits', 2);
    }

    // ── phase 2 ─────────────────────────────────────────────────────────────

    private function phaseTwo(string $uuid, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            "/api/v1/public/sites/{$this->site->slug}/special-offer-visits/{$uuid}/client-ips",
            $body,
            ['X-Site-Key' => $this->key]
        );
    }

    public function test_client_reported_addresses_are_stored_and_labelled(): void
    {
        $offer = $this->offer('special-offers');
        $payload = $this->payload($offer);

        $this->track($payload, ['REMOTE_ADDR' => '203.0.113.50'])->assertStatus(201);

        $this->phaseTwo($payload['visit_uuid'], [
            'client_ipv4' => '198.51.100.10',
            'client_ipv6' => '2001:db8::1',
        ])->assertNoContent();

        $v4 = SpecialOfferVisitIp::where('ip', '198.51.100.10')->firstOrFail();

        $this->assertSame('client_reported', $v4->source);
        $this->assertSame('client_ipv4', $v4->type);
        $this->assertSame('client_reported', SpecialOfferVisitIp::where('ip', '2001:db8::1')->firstOrFail()->source);
    }

    public function test_phase_two_rejects_garbage(): void
    {
        $offer = $this->offer('special-offers');
        $payload = $this->payload($offer);

        $this->track($payload)->assertStatus(201);

        $this->phaseTwo($payload['visit_uuid'], ['client_ipv4' => 'not-an-address'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('special_offer_visit_ips', ['ip' => 'not-an-address']);
    }

    public function test_phase_two_rejects_an_address_of_the_wrong_family(): void
    {
        $offer = $this->offer('special-offers');
        $payload = $this->payload($offer);

        $this->track($payload)->assertStatus(201);

        // An "IPv4 endpoint" that returned IPv6 did not come from where the
        // client says it did.
        $this->phaseTwo($payload['visit_uuid'], ['client_ipv4' => '2001:db8::1'])
            ->assertStatus(422);
    }

    public function test_phase_two_is_idempotent(): void
    {
        $offer = $this->offer('special-offers');
        $payload = $this->payload($offer);

        $this->track($payload)->assertStatus(201);

        $this->phaseTwo($payload['visit_uuid'], ['client_ipv4' => '198.51.100.10'])->assertNoContent();
        $this->phaseTwo($payload['visit_uuid'], ['client_ipv4' => '198.51.100.10'])->assertNoContent();

        $this->assertSame(1, SpecialOfferVisitIp::where('ip', '198.51.100.10')->count());
    }

    public function test_phase_two_for_an_unknown_visit_is_a_quiet_no_op(): void
    {
        $this->phaseTwo((string) Str::uuid(), ['client_ipv4' => '198.51.100.10'])
            ->assertNoContent();

        $this->assertDatabaseCount('special_offer_visit_ips', 0);
    }

    // ── derived flags ───────────────────────────────────────────────────────

    public function test_two_public_addresses_in_one_family_raise_the_mismatch_flag(): void
    {
        $offer = $this->offer('special-offers');
        $payload = $this->payload($offer);

        $this->track($payload, ['REMOTE_ADDR' => '203.0.113.50'])->assertStatus(201);
        $this->phaseTwo($payload['visit_uuid'], ['client_ipv4' => '198.51.100.10'])->assertNoContent();

        $visit = SpecialOfferVisit::firstOrFail();

        $this->assertTrue($visit->ip_mismatch);
        $this->assertTrue($visit->vpn_suspected);
    }

    public function test_one_address_per_family_is_not_a_mismatch(): void
    {
        $offer = $this->offer('special-offers');
        $payload = $this->payload($offer);

        // A visitor legitimately has one IPv4 and one IPv6 at the same time.
        $this->track($payload, ['REMOTE_ADDR' => '203.0.113.50'])->assertStatus(201);
        $this->phaseTwo($payload['visit_uuid'], [
            'client_ipv4' => '203.0.113.50',
            'client_ipv6' => '2001:db8::1',
        ])->assertNoContent();

        $visit = SpecialOfferVisit::firstOrFail();

        $this->assertFalse($visit->ip_mismatch);
        $this->assertFalse($visit->vpn_suspected);
    }

    // ── geo ─────────────────────────────────────────────────────────────────

    public function test_geo_job_is_queued_for_a_consenting_visit(): void
    {
        Queue::fake();
        $offer = $this->offer('special-offers');

        $this->track($this->payload($offer))->assertStatus(201);

        Queue::assertPushed(ResolveVisitGeoInfo::class);
    }

    public function test_geo_is_queued_even_without_consent(): void
    {
        /*
         * CHANGED 2026-11-27 at the site owner's direction. This used to assert
         * the opposite: no geo lookup unless the visitor answered "all". The
         * basis stated in the Privacy Policy is now legitimate interest rather
         * than consent, so the visit is resolved either way and the answer the
         * visitor gave is kept on the row instead of deciding the outcome.
         *
         * A visitor sending Global Privacy Control never reaches this endpoint:
         * OfferVisitTracker declines to report them in the browser.
         */
        Queue::fake();
        $offer = $this->offer('special-offers');

        $this->track($this->payload($offer, ['consent' => 'necessary']))->assertStatus(201);

        Queue::assertPushed(ResolveVisitGeoInfo::class);
        $this->assertSame('necessary', SpecialOfferVisit::firstOrFail()->consent);
    }

    public function test_a_visit_with_no_consent_answer_is_still_recorded(): void
    {
        $offer = $this->offer('special-offers');
        $payload = $this->payload($offer);
        unset($payload['consent']);

        $this->track($payload, ['REMOTE_ADDR' => '203.0.113.50'])->assertStatus(201);

        $visit = SpecialOfferVisit::firstOrFail();
        $this->assertNull($visit->consent);
        $this->assertSame('203.0.113.50', $visit->primary_ip);

        // Phase 2 must work for them too, or the IPv4/IPv6 columns stay empty
        // for every visitor who never touched the banner.
        $this->phaseTwo($payload['visit_uuid'], ['client_ipv4' => '198.51.100.10'])->assertNoContent();
        $this->assertDatabaseHas('special_offer_visit_ips', [
            'ip'     => '198.51.100.10',
            'source' => 'client_reported',
        ]);
    }

    public function test_private_addresses_are_never_sent_to_a_geo_provider(): void
    {
        $service = \App\Services\IpInfo\IpInfoService::make();

        $this->assertFalse($service->isResolvable('10.0.0.1'));
        $this->assertFalse($service->isResolvable('127.0.0.1'));
        $this->assertFalse($service->isResolvable('fe80::1'));
        $this->assertFalse($service->isResolvable('abc.local'));
        $this->assertTrue($service->isResolvable('203.0.113.7'));
    }

    // ── tenancy ─────────────────────────────────────────────────────────────

    public function test_a_site_key_cannot_append_to_another_sites_visit(): void
    {
        $offer = $this->offer('special-offers');
        $payload = $this->payload($offer);
        $this->track($payload)->assertStatus(201);

        $otherKey = Site::generateApiKey();
        $other = Site::create([
            'name' => 'Other', 'slug' => 'other-site', 'domain' => 'other.test',
            'api_key' => Hash::make($otherKey), 'active' => true,
        ]);

        $this->postJson(
            "/api/v1/public/sites/{$other->slug}/special-offer-visits/{$payload['visit_uuid']}/client-ips",
            ['client_ipv4' => '198.51.100.99'],
            ['X-Site-Key' => $otherKey]
        )->assertNoContent();

        $this->assertDatabaseMissing('special_offer_visit_ips', ['ip' => '198.51.100.99']);
    }

    public function test_tracking_requires_a_valid_site_key(): void
    {
        $offer = $this->offer('special-offers');

        $this->postJson(
            "/api/v1/public/sites/{$this->site->slug}/special-offer-visits",
            $this->payload($offer),
            ['X-Site-Key' => 'wrong-key']
        )->assertStatus(403);
    }
}
