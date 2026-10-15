<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * `histories:clear` — the deliberate one, as opposed to the nightly prune.
 *
 * It exists as a SEPARATE command rather than `histories:prune --months=0`
 * precisely so that the gentle, scheduled thing and the total, manual thing
 * cannot be confused for one another by a missing flag. These tests hold that
 * line: it does nothing without a `--type`, nothing on a typo, and nothing at
 * all under `--dry-run`.
 */
class ClearMessageHistoriesCommandTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteId = (int) Site::factory()->create()->id;
    }

    private function seedBoth(): void
    {
        // Two streams, both well INSIDE the retention window — the point of
        // this command is that recency does not save a row.
        DB::table('promotion_email_histories')->insert([
            'site_id'    => $this->siteId,
            'email'      => 'player@example.test',
            'sent_date'  => Carbon::now()->toDateString(),
            'status'     => 'success',
            'created_at' => Carbon::now(),
        ]);

        DB::table('phone_sms_histories')->insert([
            'phone'      => '+37255555555',
            'status'     => 'sent',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    public function test_it_clears_one_stream_and_leaves_the_others_alone(): void
    {
        $this->seedBoth();

        $this->artisan('histories:clear --type=sms --force')->assertSuccessful();

        $this->assertSame(0, DB::table('phone_sms_histories')->count());
        $this->assertSame(1, DB::table('promotion_email_histories')->count(), 'promotion was not asked for');
    }

    public function test_all_clears_every_stream(): void
    {
        $this->seedBoth();

        $this->artisan('histories:clear --type=all --force')->assertSuccessful();

        $this->assertSame(0, DB::table('phone_sms_histories')->count());
        $this->assertSame(0, DB::table('promotion_email_histories')->count());
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $this->seedBoth();

        $this->artisan('histories:clear --type=all --dry-run')->assertSuccessful();

        $this->assertSame(1, DB::table('phone_sms_histories')->count());
        $this->assertSame(1, DB::table('promotion_email_histories')->count());
    }

    public function test_it_refuses_without_a_type(): void
    {
        // No type is not "everything": a command this destructive must never
        // have a default.
        $this->seedBoth();

        $this->artisan('histories:clear --force')->assertFailed();

        $this->assertSame(1, DB::table('phone_sms_histories')->count());
    }

    public function test_it_refuses_an_unknown_type(): void
    {
        $this->seedBoth();

        $this->artisan('histories:clear --type=smss --force')->assertFailed();

        $this->assertSame(1, DB::table('phone_sms_histories')->count());
    }
}
