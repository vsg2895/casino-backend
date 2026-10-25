<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWarmupCampaignJob;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Services\Mail\EmailTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The exact bodies and status codes of the warmup run endpoints.
 *
 * The behaviour tests elsewhere assert `ok` and a status; these pin every key
 * and every operator-facing message, so moving the run logic out of the
 * controller cannot change a word the admin screen shows.
 */
class WarmupEndpointContractTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function addAddress(string $email, ?string $lastSentAt = null): WarmupEmail
    {
        $row = WarmupEmail::create(['email' => $email]);
        $row->forceFill(['last_sent_at' => $lastSentAt === null ? null : Carbon::parse($lastSentAt)])->save();

        return $row->refresh();
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload = [])
    {
        return $this->postJson('/api/v1/admin/warmup-emails/send', [
            'template' => EmailTemplateCatalog::TYPE_PROMOTION,
            ...$payload,
        ]);
    }

    public function test_send_to_an_empty_list(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey(['slug' => config('warmup.site_slug')]);

        $this->send()->assertStatus(422)->assertExactJson([
            'ok' => false,
            'message' => 'The warmup list is empty. Add or import addresses first.',
        ]);
    }

    public function test_send_without_a_configured_site(): void
    {
        $this->actingAsAdmin();
        config()->set('warmup.site_slug', '');
        $this->addAddress('seed@example.com');

        $this->send()->assertStatus(422)->assertExactJson([
            'ok' => false,
            'message' => 'No warmup site is configured. Set WARMUP_SITE_SLUG in the environment.',
        ]);
    }

    public function test_send_when_the_configured_site_is_inactive(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey(['slug' => 'warm-brand', 'active' => false]);
        config()->set('warmup.site_slug', 'warm-brand');
        $this->addAddress('seed@example.com');

        $this->send()->assertStatus(422)->assertExactJson([
            'ok' => false,
            'message' => 'Warmup is configured to send as "warm-brand", but no active site has that slug. '
                .'Register or reactivate it, or change WARMUP_SITE_SLUG.',
        ]);
    }

    public function test_send_when_the_cooldown_leaves_nobody(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        $this->addAddress('seed@example.com', '2026-08-27 09:00:00');

        $this->send(['count' => 1, 'cooldown_days' => 3])->assertStatus(422)->assertExactJson([
            'ok' => false,
            'message' => 'Every address on the list has been contacted within the last 3 day(s). '
                .'Lower the cooldown or add more addresses.',
        ]);
    }

    public function test_send_while_a_run_holds_the_lock(): void
    {
        $this->actingAsAdmin();
        $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        $this->addAddress('seed@example.com');
        Cache::lock(SendWarmupCampaignJob::runLockKey(), 900)->get();

        $this->send()->assertStatus(409)->assertExactJson([
            'ok' => false,
            'message' => 'A warmup run is already in progress. Wait for it to finish before starting another.',
        ]);
        $this->assertSame(0, WarmupSend::query()->count());
    }

    public function test_send_to_everyone_is_queued(): void
    {
        Bus::fake();
        $admin = $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['slug' => config('warmup.site_slug'), 'name' => 'Warm Brand']);
        $this->addAddress('a@example.com');
        $this->addAddress('b@example.com');

        $res = $this->send()->assertStatus(202);

        $send = WarmupSend::query()->sole();
        $res->assertExactJson([
            'ok' => true,
            'send_id' => $send->id,
            'recipients' => 2,
            'message' => 'Warmup queued: Promotion (marketing offer) template for Warm Brand to all 2 address(es).',
        ]);
        $this->assertSame($site->id, $send->site_id);
        $this->assertSame($admin->id, $send->user_id);
        $this->assertNull($send->requested_count);
        $this->assertNull($send->cooldown_days);
        Bus::assertDispatched(SendWarmupCampaignJob::class);
    }

    public function test_send_with_a_count_and_cooldown_is_queued(): void
    {
        Bus::fake();
        $this->actingAsAdmin();
        $this->siteWithKey(['slug' => config('warmup.site_slug'), 'name' => 'Warm Brand']);
        $this->addAddress('a@example.com');
        $this->addAddress('b@example.com');
        $this->addAddress('c@example.com', '2026-08-27 09:00:00');

        $res = $this->send(['count' => 3, 'cooldown_days' => 2])->assertStatus(202);

        $res->assertExactJson([
            'ok' => true,
            'send_id' => WarmupSend::query()->sole()->id,
            'recipients' => 2,
            'message' => 'Warmup queued: Promotion (marketing offer) template for Warm Brand to '
                .'2 most recently added address(es) not contacted in the last 2 day(s).',
        ]);
    }

    public function test_cancel_with_no_run(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/warmup-emails/cancel')->assertOk()->assertExactJson([
            'ok' => true,
            'warmup_send_id' => null,
            'lock_freed' => true,
            'queued_work_stopped' => true,
            'message' => 'No run was in progress. The lock has been cleared, so a new run can start.',
        ]);
    }

    public function test_cancel_stops_the_latest_open_run(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        $send = WarmupSend::create(['site_id' => $site->id, 'template' => EmailTemplateCatalog::TYPE_PROMOTION]);
        Cache::lock(SendWarmupCampaignJob::runLockKey(), 900)->get();

        $this->postJson('/api/v1/admin/warmup-emails/cancel')->assertOk()->assertExactJson([
            'ok' => true,
            'warmup_send_id' => $send->id,
            'lock_freed' => true,
            'queued_work_stopped' => true,
            'message' => "Run #{$send->id} stopped. Queued batches will not send, and a new run can start.",
        ]);
        $this->assertNotNull($send->refresh()->cancelled_at);
        $this->assertTrue(Cache::lock(SendWarmupCampaignJob::runLockKey(), 900)->get());
    }

    public function test_recipients_summary(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['slug' => config('warmup.site_slug'), 'name' => 'Warm Brand']);
        $this->addAddress('a@example.com');
        $this->addAddress('b@example.com', '2026-08-27 09:00:00');

        $this->getJson('/api/v1/admin/warmup-emails/recipients?count=5&cooldown_days=999')
            ->assertOk()
            ->assertExactJson(['data' => [
                'total' => 2,
                'eligible' => 1,
                'recipients' => 1,
                'count' => 5,
                'cooldown_days' => WarmupSend::MAX_COOLDOWN_DAYS,
                'min_cooldown_days' => WarmupSend::MIN_COOLDOWN_DAYS,
                'max_cooldown_days' => WarmupSend::MAX_COOLDOWN_DAYS,
                'default_cooldown_days' => 1,
                'site_id' => $site->id,
                'site_name' => 'Warm Brand',
                'site_slug' => config('warmup.site_slug'),
            ]]);
    }

    public function test_recipients_preview_meta(): void
    {
        $this->actingAsAdmin();
        $this->addAddress('a@example.com');
        $this->addAddress('b@example.com');
        $this->addAddress('c@example.com');

        $res = $this->getJson('/api/v1/admin/warmup-emails/recipients/preview?count=2')->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame([
            'total' => 3,
            'eligible_count' => 3,
            'would_reach' => 2,
            'preview_count' => 2,
            'preview_limit' => 200,
            'truncated' => false,
            'cooldown_days' => null,
            'count' => 2,
        ], $res->json('meta'));
    }

    public function test_last_send_before_any_run(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/warmup-emails/last-send')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_last_send_keys_in_order(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey(['slug' => config('warmup.site_slug')]);
        WarmupSend::create(['site_id' => $site->id, 'template' => EmailTemplateCatalog::TYPE_PROMOTION]);

        $this->assertSame([
            'id', 'started_at', 'requested_count', 'queued_count', 'sent_count', 'failed_count',
            'attempted_count', 'cooldown_days', 'template', 'template_label', 'site_name', 'sent_by',
            'in_progress', 'cancelled_at',
        ], array_keys($this->getJson('/api/v1/admin/warmup-emails/last-send')->assertOk()->json('data')));
    }
}
