<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\MailgunKeyResource;
use App\Http\Resources\SmtpCredentialResource;
use App\Http\Resources\SpecialOfferResource;
use App\Http\Resources\UniOne\UniOneSendResource;
use App\Models\BonusCategory;
use App\Models\Casino;
use App\Models\CasinoReview;
use App\Models\MailgunKey;
use App\Models\SmtpCredential;
use App\Models\Site;
use App\Models\SpecialOffer;
use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneSend;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The cost of an admin listing, pinned.
 *
 * These are performance tests that assert on BEHAVIOUR rather than on a clock,
 * because the two properties that actually made the panel slow are both exactly
 * checkable:
 *
 *   1. the number of queries a listing runs must not depend on how many rows it
 *      returns — the definition of "no N+1";
 *   2. a listing that skips columns must return byte-identical JSON to the
 *      `SELECT *` it replaced.
 *
 * Neither can be verified by reading the code once and moving on: the first
 * breaks the next time a field is added to a resource, and the second breaks the
 * next time a column is added to a table. Both breakages are silent in
 * production — a slower screen, or a field that quietly becomes null.
 *
 * ── What was wrong ──────────────────────────────────────────────────────────
 *
 * `SpecialOfferResource` renders `bonus_category_slug` UNCONDITIONALLY (it is
 * not behind `whenLoaded`, deliberately: the front end decides which bonuses
 * carry a Claim button from it). The admin listing did not eager-load that
 * relation, so it issued one SELECT against `bonus_categories` per offer — and
 * that listing is unpaginated, so the cost grew with the catalogue for ever.
 *
 * `MailgunKey`, `SmtpCredential` and `UniOneSend` each carry a LONGTEXT message
 * body that NO response returns. Their listings selected it anyway, pulling the
 * full rendered campaign for every row out of MySQL and throwing it away.
 */
class AdminListingPerformanceTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    // ── 1. query count must not track row count ──────────────────────────────

    public function test_the_offers_listing_costs_the_same_whether_it_returns_two_offers_or_twenty(): void
    {
        $this->actingAsAdmin();

        $category = BonusCategory::create(['name' => 'No Deposit', 'position' => 1, 'active' => true]);

        $this->offers($category, 2);
        $cheap = $this->countQueries('/api/v1/admin/special-offers');

        $this->offers($category, 18);
        $expensive = $this->countQueries('/api/v1/admin/special-offers');

        // Before the fix: 2 offers cost 5 queries and 20 cost 23.
        $this->assertSame(
            $cheap,
            $expensive,
            'the offers listing must not issue a query per offer — eager-load every relation the resource renders',
        );
    }

    public function test_the_offers_listing_still_names_the_bonus_category_of_every_row(): void
    {
        // The counterpart to the test above: eager-loading is only correct if
        // the field it feeds is still there.
        $this->actingAsAdmin();

        $category = BonusCategory::create(['name' => 'Free Spins', 'position' => 2, 'active' => true]);
        $this->offers($category, 3);

        $response = $this->getJson('/api/v1/admin/special-offers')->assertOk();

        $this->assertCount(3, $response->json('data'));

        foreach ($response->json('data') as $offer) {
            $this->assertSame($category->slug, $offer['bonus_category_slug']);
        }
    }

    public function test_the_casino_form_loads_the_bonus_category_of_every_offer_it_nests(): void
    {
        /*
         * The same unconditional field, reached by a different route:
         * CasinoResource nests SpecialOfferResource, so the casino edit form
         * paid the same per-offer query.
         *
         * preventLazyLoading turns that into an exception instead of a silent
         * cost, which is the only way a single-casino screen can prove it — one
         * casino with five offers is five extra queries, well inside the noise
         * of a query count.
         */
        $this->actingAsAdmin();

        $category = BonusCategory::create(['name' => 'Cashback', 'position' => 3, 'active' => true]);
        $casino = Casino::factory()->create();

        SpecialOffer::factory()->count(5)->create([
            'casino_id'         => $casino->id,
            'bonus_category_id' => $category->id,
        ]);

        Model::preventLazyLoading();

        try {
            $this->getJson("/api/v1/admin/casinos/{$casino->id}")
                ->assertOk()
                ->assertJsonPath('data.special_offers.0.bonus_category_slug', $category->slug);
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_saving_a_casino_resolves_its_site_list_once_not_once_per_child(): void
    {
        /*
         * Not a listing, but the same bug on the write side, and the one an
         * operator feels most: CasinoSearchObserver re-indexes the casino's
         * offers and reviews on every save, and each child used to re-read the
         * parent casino and re-query `casino_site` for itself. A casino with a
         * dozen offers and a hundred reviews turned one Save into hundreds of
         * queries for two values that cannot change inside the loop.
         *
         * The assertions are on the SQL actually issued rather than on a total,
         * because a total legitimately grows with the number of children: each
         * one still writes its own index rows.
         */
        $category = BonusCategory::create(['name' => 'Reload', 'position' => 4, 'active' => true]);
        $site = Site::factory()->create();
        $casino = Casino::factory()->create(['active' => true]);
        $casino->sites()->attach($site->id, [
            'active'        => true,
            'position'      => 1,
            'affiliate_url' => 'https://example.test/go',
        ]);

        SpecialOffer::factory()->count(6)->create([
            'casino_id'         => $casino->id,
            'bonus_category_id' => $category->id,
        ]);

        foreach (range(1, 6) as $i) {
            CasinoReview::create([
                'casino_id' => $casino->id,
                'site_id'   => $site->id,
                'author_name' => "Player {$i}",
                'rating'    => 4,
                'body'      => 'Fast payouts.',
                'status'    => CasinoReview::STATUS_PUBLISHED,
            ]);
        }

        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = (string) $query->sql;
        });

        // The save the admin's Save button makes.
        $casino->forceFill(['name' => $casino->name . ' Casino'])->save();

        app('events')->forget('Illuminate\\Database\\Events\\QueryExecuted');

        // `FROM casino_site` only — the derived-visibility queries JOIN that
        // pivot and are a different question, asked once each.
        $this->assertSame(
            1,
            $this->countSelectsFrom($sql, 'casino_site'),
            'the casino\'s site list must be resolved once per save, not once per offer and review',
        );

        $this->assertSame(
            1,
            $this->countSelectsFrom($sql, 'bonus_categories'),
            'offers sharing a bonus type must not each re-derive that type\'s visibility',
        );

        // And the parent is never re-read: the cascade already holds it.
        $this->assertSame(
            0,
            $this->countSelectsFrom($sql, 'casinos'),
            'the cascade must not re-read the casino it was handed, once per child',
        );
    }

    // ── 2. trimmed listings must render identical JSON ───────────────────────

    public function test_the_offers_listing_renders_the_same_site_block_from_five_site_columns(): void
    {
        /*
         * The listing names the site columns it needs instead of selecting
         * every one. The pivot is what makes this worth asserting: Eloquent
         * appends the pivot columns to a restricted belongsToMany select on its
         * own, and if it ever stopped, the admin would quietly show an empty
         * affiliate URL for every offer.
         */
        $site = Site::factory()->create();
        $casino = Casino::factory()->create();
        $casino->sites()->attach($site->id, [
            'active'        => true,
            'position'      => 3,
            'featured'      => true,
            'affiliate_url' => 'https://example.test/go',
        ]);

        $offer = SpecialOffer::factory()->create(['casino_id' => $casino->id]);

        $full = SpecialOffer::with(['casino.sites', 'bonusCategory'])->findOrFail($offer->id);
        $trimmed = SpecialOffer::with([
            'casino.sites:sites.id,sites.name,sites.slug,sites.domain,sites.revalidation_url',
            'bonusCategory',
        ])->findOrFail($offer->id);

        /*
         * Compared as JSON, not as `resolve()` output: `resolve()` leaves a
         * NESTED resource (here `casino`) as an object, so comparing the two
         * would compare the underlying Eloquent models — which of course differ,
         * because that is the whole point of the change. The wire format is the
         * contract.
         */
        $rendered = $this->serialise(new SpecialOfferResource($trimmed));

        $this->assertEquals(
            $this->serialise(new SpecialOfferResource($full)),
            $rendered,
            'restricting the site columns changed the response',
        );

        // Named explicitly, because an equal-but-empty pivot would also pass above.
        $this->assertSame('https://example.test/go', $rendered['casino']['sites'][0]['affiliate_url']);
        $this->assertSame(3, $rendered['casino']['sites'][0]['position']);
        $this->assertTrue($rendered['casino']['sites'][0]['featured']);
    }


    public function test_the_mailgun_credential_listing_renders_identically_without_the_message_body(): void
    {
        $key = new MailgunKey();
        $key->forceFill([
            'name'         => 'Primary',
            'domain'       => 'mg.example.com',
            'api_key'      => 'key-0123456789abcdef',
            'region'       => MailgunKey::REGION_EU,
            'from_address' => 'news@example.com',
            'from_name'    => 'Example',
            'status'       => MailgunKey::STATUS_ACTIVE,
        ])->save();

        // Written past the casts so this test does not depend on how the heavy
        // columns are cast — only on their being excluded.
        DB::table('mailgun_keys')->where('id', $key->id)->update([
            'message_html'     => str_repeat('<p>campaign</p>', 500),
            'message_template' => json_encode(['blocks' => ['intro']], JSON_THROW_ON_ERROR),
            'receiver_sources' => json_encode(['our_clients'], JSON_THROW_ON_ERROR),
        ]);

        $this->assertIdenticalResource(
            MailgunKeyResource::class,
            MailgunKey::query(),
            MailgunKey::LIST_COLUMNS,
            'mailgun_keys',
            // The campaign settings are edited and read through the dedicated
            // receiver-settings endpoints, never through this resource.
            [
                'message_html', 'message_template', 'receiver_sources',
                'send_enabled', 'batch_size', 'selection_order', 'cooldown_days',
                'only_active', 'last_run_at', 'message_subject',
            ],
            (int) $key->id,
        );
    }

    public function test_the_smtp_credential_listing_renders_identically_without_the_message_body(): void
    {
        $credential = new SmtpCredential();
        $credential->forceFill([
            'name'         => 'Own server',
            'type'         => SmtpCredential::TYPE_OWN_SMTP,
            'host'         => 'mail.example.com',
            'port'         => 587,
            'username'     => 'news@example.com',
            'password'     => 'secret-password',
            'encryption'   => 'tls',
            'from_address' => 'news@example.com',
            'from_name'    => 'Example',
            'status'       => SmtpCredential::STATUS_ACTIVE,
        ])->save();

        DB::table('smtp_credentials')->where('id', $credential->id)->update([
            'message_html'     => str_repeat('<p>campaign</p>', 500),
            'message_template' => json_encode(['blocks' => ['intro']], JSON_THROW_ON_ERROR),
            'receiver_sources' => json_encode(['external_clients'], JSON_THROW_ON_ERROR),
        ]);

        $this->assertIdenticalResource(
            SmtpCredentialResource::class,
            SmtpCredential::query(),
            SmtpCredential::LIST_COLUMNS,
            'smtp_credentials',
            // As above — and `last_run_at` IS selected here, because this
            // resource shows it.
            [
                'message_html', 'message_template', 'receiver_sources',
                'batch_size', 'selection_order', 'cooldown_days', 'only_active',
                'message_subject',
            ],
            (int) $credential->id,
        );
    }

    public function test_the_unione_send_log_renders_identically_without_the_message_bodies(): void
    {
        $key = UniOneApiKey::create([
            'name' => 'Primary', 'api_key' => 'secret', 'key_type' => 'user',
            'region' => 'eu1', 'is_active' => true,
        ]);

        $send = UniOneSend::create([
            'unione_api_key_id' => $key->id,
            'subject'           => 'October offers',
            'from_email'        => 'news@example.com',
            'from_name'         => 'Example',
            'html_body'         => str_repeat('<p>campaign</p>', 500),
            'plaintext_body'    => str_repeat('campaign ', 500),
            'requested_count'   => 1200,
            'eligible_count'    => 1100,
            'accepted_count'    => 1090,
            'failed_count'      => 10,
            'chunk_count'       => 3,
            'status'            => UniOneSend::STATUS_COMPLETED,
        ]);

        $this->assertIdenticalResource(
            UniOneSendResource::class,
            UniOneSend::query(),
            UniOneSend::LIST_COLUMNS,
            'unione_sends',
            ['html_body', 'plaintext_body'],
            (int) $send->id,
        );
    }

    public function test_the_unione_send_log_endpoint_returns_the_same_fields_it_always_did(): void
    {
        // The parity check above is on the resource; this one is on the wire,
        // through the controller that now passes LIST_COLUMNS to paginate().
        $this->actingAsAdmin();

        $key = UniOneApiKey::create([
            'name' => 'Primary', 'api_key' => 'secret', 'key_type' => 'user',
            'region' => 'eu1', 'is_active' => true,
        ]);

        UniOneSend::create([
            'unione_api_key_id' => $key->id,
            'subject'           => 'October offers',
            'from_email'        => 'news@example.com',
            'html_body'         => '<p>campaign</p>',
            'plaintext_body'    => 'campaign',
            'requested_count'   => 10,
            'status'            => UniOneSend::STATUS_COMPLETED,
        ]);

        $this->getJson('/api/v1/admin/unione/sends')
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'October offers')
            ->assertJsonPath('data.0.status', UniOneSend::STATUS_COMPLETED)
            ->assertJsonPath('data.0.requested_count', 10)
            ->assertJsonPath('data.0.key.name', 'Primary');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param  list<string>  $offersCount */
    private function offers(BonusCategory $category, int $count): void
    {
        SpecialOffer::factory()->count($count)->create([
            'bonus_category_id' => $category->id,
        ]);
    }

    /**
     * A resource exactly as the client receives it — nested resources resolved,
     * dates stringified.
     *
     * @return array<string, mixed>
     */
    private function serialise(\Illuminate\Http\Resources\Json\JsonResource $resource): array
    {
        return json_decode(json_encode($resource, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * SELECTs whose FROM is this table — not ones that merely join it.
     *
     * The identifier quoting differs by driver (SQLite under test, MySQL in
     * production), so both are accepted.
     *
     * @param  list<string>  $sql
     */
    private function countSelectsFrom(array $sql, string $table): int
    {
        $pattern = '/^\s*select\b.*\bfrom\s+["`]?' . preg_quote($table, '/') . '["`]?/is';

        return count(array_filter($sql, static fn (string $q): bool => preg_match($pattern, $q) === 1));
    }

    /** How many queries one GET takes, listener removed afterwards. */
    private function countQueries(string $uri): int
    {
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson($uri)->assertOk();

        // One listener per call would otherwise still be attached on the next,
        // and every live listener increments its own counter — which is how a
        // naive profiler reports multiples of the real number.
        DB::flushQueryLog();
        app('events')->forget('Illuminate\Database\Events\QueryExecuted');

        return $queries;
    }

    /**
     * A trimmed SELECT must render what `SELECT *` renders — and must really
     * have left the heavy columns behind.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource
     * @param  \Illuminate\Database\Eloquent\Builder<covariant Model>  $query
     * @param  list<string>  $columns
     * @param  list<string>  $excluded
     */
    private function assertIdenticalResource(
        string $resource,
        $query,
        array $columns,
        string $table,
        array $excluded,
        int $id,
    ): void {
        $present = Schema::getColumnListing($table);

        foreach ($columns as $column) {
            $this->assertContains($column, $present, "LIST_COLUMNS names [{$column}], which {$table} does not have");
        }

        // Every column of the table is either selected or deliberately left
        // out. This is the drift guard: add a column, and it must be a decision.
        $this->assertSame(
            [],
            array_values(array_diff($present, $columns, $excluded)),
            "a column was added to {$table} without deciding whether the listing needs it"
                . ' — add it to LIST_COLUMNS, or to this test\'s excluded list',
        );

        $full = (clone $query)->findOrFail($id);
        $trimmed = (clone $query)->select($columns)->findOrFail($id);

        foreach ($excluded as $column) {
            $this->assertArrayHasKey($column, $full->getAttributes());
            $this->assertArrayNotHasKey($column, $trimmed->getAttributes(), "{$column} is still being selected");
        }

        $this->assertEquals(
            $this->serialise(new $resource($full)),
            $this->serialise(new $resource($trimmed)),
            'the trimmed listing changed the response',
        );
    }
}
