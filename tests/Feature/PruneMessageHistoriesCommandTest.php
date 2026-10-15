<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use App\Models\UniOne\UniOneApiKey;
use App\Models\UniOne\UniOneSend;
use App\Models\UniOne\UniOneSendChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `histories:prune` — the retention sweep behind every tab of Email History.
 *
 * What these tests are for: the command deletes recipients' message logs on a
 * daily schedule with nobody watching, so the two properties that matter are
 * that it takes everything past the window and NOTHING inside it. A retention
 * job that is one comparison out either keeps personal data for ever or erases
 * the week an operator is currently investigating, and both failures are
 * silent.
 *
 * The partition path of `promotion_email_histories` is MySQL-only and is not
 * exercised here (the suite runs on SQLite); it is guarded by a driver check in
 * the command, and the row-by-row delete these tests cover is what runs when
 * that path is unavailable.
 */
class PruneMessageHistoriesCommandTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function siteId(): int
    {
        // One site, created once per test — these rows are site-scoped and the
        // column is NOT NULL.
        return $this->siteId ??= (int) Site::factory()->create()->id;
    }

    private ?int $siteId = null;

    private function oldDate(): Carbon
    {
        // Comfortably outside the two-month default.
        return Carbon::now('UTC')->subMonths(4);
    }

    private function recentDate(): Carbon
    {
        return Carbon::now('UTC')->subDays(3);
    }

    /** One promotion-history row, written through the query builder (no model). */
    private function promotionRow(Carbon $sentDate): void
    {
        DB::table('promotion_email_histories')->insert([
            'site_id'    => $this->siteId(),
            'email'      => 'player+' . $sentDate->timestamp . '@example.test',
            'sent_date'  => $sentDate->toDateString(),
            'status'     => 'success',
            'created_at' => $sentDate,
        ]);
    }

    private function smsRow(Carbon $at): void
    {
        DB::table('phone_sms_histories')->insert([
            'phone'      => '+3725' . $at->timestamp,
            'status'     => 'sent',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function test_it_deletes_history_past_the_window_and_keeps_what_is_inside_it(): void
    {
        $this->promotionRow($this->oldDate());
        $this->promotionRow($this->recentDate());
        $this->smsRow($this->oldDate());
        $this->smsRow($this->recentDate());

        $this->artisan('histories:prune')->assertSuccessful();

        $this->assertSame(1, DB::table('promotion_email_histories')->count(), 'only the old promotion row should go');
        $this->assertSame(1, DB::table('phone_sms_histories')->count(), 'only the old SMS row should go');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->promotionRow($this->oldDate());
        $this->smsRow($this->oldDate());

        $this->artisan('histories:prune --dry-run')->assertSuccessful();

        $this->assertSame(1, DB::table('promotion_email_histories')->count());
        $this->assertSame(1, DB::table('phone_sms_histories')->count());
    }

    public function test_only_limits_the_sweep_to_the_named_streams(): void
    {
        // The escape hatch for a one-off: pruning SMS must not touch promotion.
        $this->promotionRow($this->oldDate());
        $this->smsRow($this->oldDate());

        $this->artisan('histories:prune --only=sms')->assertSuccessful();

        $this->assertSame(1, DB::table('promotion_email_histories')->count(), 'promotion was not asked for');
        $this->assertSame(0, DB::table('phone_sms_histories')->count());
    }

    public function test_an_unknown_stream_name_fails_instead_of_pruning_everything(): void
    {
        // A typo in a scheduled command must not quietly mean "all of them".
        $this->promotionRow($this->oldDate());

        $this->artisan('histories:prune --only=smss')->assertFailed();

        $this->assertSame(1, DB::table('promotion_email_histories')->count());
    }

    public function test_pruning_a_unione_run_takes_its_chunks_with_it(): void
    {
        /*
         * The reason the command prunes the PARENT rather than the rows the
         * screen shows: chunks and recipients are ON DELETE CASCADE, so a run
         * leaves whole. Deleting the children directly would strip the detail
         * out from under runs still listed in the admin.
         */
        $key = UniOneApiKey::create([
            'name' => 'Primary', 'api_key' => 'secret', 'key_type' => 'user',
            'region' => 'eu1', 'is_active' => true,
        ]);

        $old = UniOneSend::create([
            'unione_api_key_id' => $key->id,
            'subject'           => 'Spring offers',
            'from_email'        => 'news@example.test',
            'html_body'         => '<p>hi</p>',
            'requested_count'   => 10,
            'status'            => UniOneSend::STATUS_COMPLETED,
        ]);
        $old->forceFill(['created_at' => $this->oldDate()])->save();

        UniOneSendChunk::create([
            'unione_send_id'  => $old->id,
            'chunk_index'     => 0,
            // NOT NULL: it is what makes a retry of this chunk idempotent at
            // UniOne's end.
            'idempotence_key' => 'chunk-' . $old->id . '-0',
            'status'          => 'committed',
            'recipient_count' => 10,
        ]);

        $recent = UniOneSend::create([
            'unione_api_key_id' => $key->id,
            'subject'           => 'This week',
            'from_email'        => 'news@example.test',
            'html_body'         => '<p>hi</p>',
            'requested_count'   => 10,
            'status'            => UniOneSend::STATUS_COMPLETED,
        ]);

        $this->artisan('histories:prune')->assertSuccessful();

        $this->assertDatabaseMissing('unione_sends', ['id' => $old->id]);
        $this->assertDatabaseHas('unione_sends', ['id' => $recent->id]);
        $this->assertSame(
            0,
            DB::table('unione_send_chunks')->where('unione_send_id', $old->id)->count(),
            'the cascade should have taken the chunk',
        );
    }

    public function test_the_window_is_configurable(): void
    {
        // A row six weeks old survives the default two months and does not
        // survive --months=1. Same row, same command, one option apart.
        $sixWeeks = Carbon::now('UTC')->subWeeks(6);
        $this->smsRow($sixWeeks);

        $this->artisan('histories:prune')->assertSuccessful();
        $this->assertSame(1, DB::table('phone_sms_histories')->count());

        $this->artisan('histories:prune --months=1')->assertSuccessful();
        $this->assertSame(0, DB::table('phone_sms_histories')->count());
    }
}
