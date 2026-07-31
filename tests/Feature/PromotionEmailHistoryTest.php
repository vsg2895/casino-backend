<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPromotionBatchJob;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Site;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class PromotionEmailHistoryTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    // ── Recording (additive, bulk, delivered-only) ────────────────────────

    public function test_delivered_emails_are_written_to_history(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'a@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'b@example.com']);

        (new SendPromotionBatchJob($site->id, ['a@example.com', 'b@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        $this->assertDatabaseCount('promotion_email_histories', 2);
        $this->assertDatabaseHas('promotion_email_histories', [
            'site_id'   => $site->id,
            'email'     => 'a@example.com',
            'sent_date' => now()->toDateString(),
            'status'    => 'success',
        ]);
    }

    public function test_history_is_written_in_a_single_bulk_insert(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        foreach (['x@example.com', 'y@example.com', 'z@example.com'] as $e) {
            Newsletter::create(['site_id' => $site->id, 'email' => $e]);
        }

        DB::enableQueryLog();
        (new SendPromotionBatchJob($site->id, ['x@example.com', 'y@example.com', 'z@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        // Any INSERT variant (incl. the idempotent insert-or-ignore) targeting
        // the history table — phrasing-agnostic across drivers.
        $historyInserts = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_starts_with(ltrim(strtolower($q['query'])), 'insert')
                && str_contains(strtolower($q['query']), 'promotion_email_histories'))
            ->count();

        // One INSERT for the whole batch — never one per recipient.
        $this->assertSame(1, $historyInserts);
        $this->assertDatabaseCount('promotion_email_histories', 3);
    }

    public function test_failed_recipient_is_recorded_as_failed(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'bad@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'good@example.com']);

        $real = app(PromotionEmailService::class);
        $service = \Mockery::mock(PromotionEmailService::class);
        $service->shouldReceive('mailFor')->andReturnUsing(
            function ($site, $template, string $email, string $token) use ($real) {
                if ($email === 'bad@example.com') {
                    throw new \RuntimeException('boom');
                }

                return $real->mailFor($site, $template, $email, $token);
            },
        );

        (new SendPromotionBatchJob($site->id, ['bad@example.com', 'good@example.com']))->handle($service, app(PromotionMailerFactory::class));

        $this->assertDatabaseHas('promotion_email_histories', ['email' => 'good@example.com', 'status' => 'success']);
        $this->assertDatabaseHas('promotion_email_histories', ['email' => 'bad@example.com', 'status' => 'failed']);
        $this->assertDatabaseMissing('promotion_email_histories', ['email' => 'bad@example.com', 'status' => 'success']);
    }

    // ── Failure reason (error column) ─────────────────────────────────────

    /** Batch job whose send throws $message for $failingEmail. */
    private function runBatchFailingWith(Site $site, string $failingEmail, string $message): void
    {
        $real = app(PromotionEmailService::class);
        $service = \Mockery::mock(PromotionEmailService::class);
        $service->shouldReceive('mailFor')->andReturnUsing(
            function ($s, $template, string $email, string $token) use ($real, $failingEmail, $message) {
                if ($email === $failingEmail) {
                    throw new \RuntimeException($message);
                }

                return $real->mailFor($s, $template, $email, $token);
            },
        );

        (new SendPromotionBatchJob($site->id, [$failingEmail, 'fine@example.com']))
            ->handle($service, app(PromotionMailerFactory::class));
    }

    public function test_failed_attempt_stores_the_error_message(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'bad@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'fine@example.com']);

        $this->runBatchFailingWith($site, 'bad@example.com', 'SMTP connect() failed');

        $this->assertDatabaseHas('promotion_email_histories', [
            'email'  => 'bad@example.com',
            'status' => 'failed',
            'error'  => 'SMTP connect() failed',
        ]);
    }

    public function test_successful_and_skipped_attempts_store_no_error(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'ok@example.com']);
        $service = app(PromotionEmailService::class);

        // First run delivers; the second is skipped by the 24h dedup.
        (new SendPromotionBatchJob($site->id, ['ok@example.com']))->handle($service, app(PromotionMailerFactory::class));
        (new SendPromotionBatchJob($site->id, ['ok@example.com']))->handle($service, app(PromotionMailerFactory::class));

        $this->assertDatabaseHas('promotion_email_histories', ['status' => 'success', 'error' => null]);
        $this->assertDatabaseHas('promotion_email_histories', ['status' => 'skipped', 'error' => null]);
    }

    public function test_a_very_long_error_is_truncated(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'bad@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'fine@example.com']);

        $this->runBatchFailingWith($site, 'bad@example.com', str_repeat('x', 5000));

        $stored = (string) PromotionEmailHistory::query()->where('status', 'failed')->value('error');

        // Capped (500 chars + the ellipsis marker), never the raw 5000.
        $this->assertLessThanOrEqual(501, mb_strlen($stored));
        $this->assertStringEndsWith('…', $stored);
    }

    public function test_repeat_failure_records_the_latest_error_and_adds_no_row(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'bad@example.com']);
        Newsletter::create(['site_id' => $site->id, 'email' => 'fine@example.com']);

        $this->runBatchFailingWith($site, 'bad@example.com', 'first failure');
        $this->runBatchFailingWith($site, 'bad@example.com', 'second failure');

        // Upsert on the unique key: still one failed row per email/day, but the
        // reason reflects the most recent attempt.
        $this->assertSame(1, PromotionEmailHistory::query()->where('status', 'failed')->count());
        $this->assertDatabaseHas('promotion_email_histories', [
            'email'  => 'bad@example.com',
            'status' => 'failed',
            'error'  => 'second failure',
        ]);
    }

    public function test_repeat_delivery_does_not_shift_the_dedup_timestamp(): void
    {
        Mail::fake();
        \Illuminate\Support\Carbon::setTestNow($start = \Illuminate\Support\Carbon::create(2026, 5, 15, 3, 0));
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'anchor@example.com']);
        $service = app(PromotionEmailService::class);

        (new SendPromotionBatchJob($site->id, ['anchor@example.com']))->handle($service, app(PromotionMailerFactory::class));

        // A later same-day run is skipped; the success row's created_at must NOT
        // move forward, or the 24h window would creep and delay tomorrow's send.
        \Illuminate\Support\Carbon::setTestNow($start->copy()->addHours(6));
        (new SendPromotionBatchJob($site->id, ['anchor@example.com']))->handle($service, app(PromotionMailerFactory::class));

        $success = PromotionEmailHistory::query()->where('status', 'success')->first();
        $this->assertSame($start->format('Y-m-d H:i:s'), $success->created_at->format('Y-m-d H:i:s'));
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_duplicate_run_records_a_skip_and_never_a_second_success(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'dup@example.com']);
        $service = app(PromotionEmailService::class);

        (new SendPromotionBatchJob($site->id, ['dup@example.com']))->handle($service, app(PromotionMailerFactory::class));
        (new SendPromotionBatchJob($site->id, ['dup@example.com']))->handle($service, app(PromotionMailerFactory::class)); // same day

        $this->assertSame(1, PromotionEmailHistory::query()->where('status', 'success')->count());
        $this->assertSame(1, PromotionEmailHistory::query()->where('status', 'skipped')->count());
    }

    // ── 24-hour dedup window ──────────────────────────────────────────────

    /** Seed a delivered (success) history row at a specific moment. */
    private function seedSuccessAt(int $siteId, string $email, \Illuminate\Support\Carbon $when): void
    {
        PromotionEmailHistory::insert([
            'site_id'    => $siteId,
            'email'      => $email,
            'sent_date'  => $when->toDateString(),
            'status'     => 'success',
            'created_at' => $when,
        ]);
    }

    public function test_delivery_across_midnight_is_still_deduped_within_24_hours(): void
    {
        Mail::fake();
        \Illuminate\Support\Carbon::setTestNow($now = \Illuminate\Support\Carbon::create(2026, 5, 15, 1, 0));
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'night@example.com']);
        // Delivered yesterday 23:00 — a different calendar day, but only 2h ago.
        $this->seedSuccessAt($site->id, 'night@example.com', $now->copy()->subHours(2));

        (new SendPromotionBatchJob($site->id, ['night@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertNothingSent();
        $this->assertDatabaseHas('promotion_email_histories', ['email' => 'night@example.com', 'status' => 'skipped']);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_delivery_older_than_24_hours_is_sent_again(): void
    {
        Mail::fake();
        \Illuminate\Support\Carbon::setTestNow($now = \Illuminate\Support\Carbon::create(2026, 5, 15, 3, 0));
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'back@example.com']);
        $this->seedSuccessAt($site->id, 'back@example.com', $now->copy()->subHours(25));

        (new SendPromotionBatchJob($site->id, ['back@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSentCount(1);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_daily_schedule_is_not_skip_flapped_by_scheduler_jitter(): void
    {
        Mail::fake();
        \Illuminate\Support\Carbon::setTestNow($now = \Illuminate\Support\Carbon::create(2026, 5, 15, 3, 0, 2));
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'daily@example.com']);
        // Yesterday's daily run fired seconds later than today's — 23h59m57s
        // ago. A strict 24h window would skip today; the jitter tolerance must
        // let it send.
        $this->seedSuccessAt($site->id, 'daily@example.com', $now->copy()->subDay()->addSeconds(5));

        (new SendPromotionBatchJob($site->id, ['daily@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        Mail::assertSentCount(1);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_failed_attempt_does_not_block_a_later_delivery(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'retry@example.com']);
        // An earlier run failed for this address today.
        PromotionEmailHistory::recordAttempts($site->id, ['retry@example.com' => 'failed']);

        (new SendPromotionBatchJob($site->id, ['retry@example.com']))
            ->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));

        // Only SUCCESS rows dedup — the failure never suppresses the send.
        Mail::assertSentCount(1);
        $this->assertDatabaseHas('promotion_email_histories', ['email' => 'retry@example.com', 'status' => 'success']);
    }

    // ── Admin listing: filters + prefix search ────────────────────────────

    private function seedHistory(Site $site, string $email, string $date): void
    {
        PromotionEmailHistory::insert([
            'site_id' => $site->id, 'email' => $email, 'sent_date' => $date, 'created_at' => now(),
        ]);
    }

    public function test_index_filters_by_site_and_date_range(): void
    {
        $this->actingAsAdmin();
        [$siteA] = $this->siteWithKey();
        [$siteB] = $this->siteWithKey();
        $this->seedHistory($siteA, 'in@example.com', '2026-05-10');
        $this->seedHistory($siteA, 'early@example.com', '2026-04-01'); // before range
        $this->seedHistory($siteB, 'other@example.com', '2026-05-10'); // other site

        $this->getJson("/api/v1/admin/promotion-history?site_id={$siteA->id}&from=2026-05-01&to=2026-05-31")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'in@example.com');
    }

    public function test_email_search_is_prefix_only(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->seedHistory($site, 'john@example.com', '2026-05-10');
        $this->seedHistory($site, 'ajohn@example.com', '2026-05-10'); // 'john' is NOT a prefix here

        $this->getJson('/api/v1/admin/promotion-history?search=john')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'john@example.com');
    }

    public function test_search_wildcards_are_escaped(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->seedHistory($site, 'real@example.com', '2026-05-10');

        // A bare '%' must not act as "match everything".
        $this->getJson('/api/v1/admin/promotion-history?search=%25')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_index_exposes_and_filters_by_status(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();
        $this->seedHistory($site, 'ok@example.com', '2026-05-10'); // defaults to success
        PromotionEmailHistory::insert([
            'site_id' => $site->id, 'email' => 'broken@example.com', 'sent_date' => '2026-05-10',
            'status' => 'failed', 'error' => 'Mailbox unavailable', 'created_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/promotion-history?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'broken@example.com')
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.error', 'Mailbox unavailable');

        // An unknown status value is ignored, not an error.
        $this->getJson('/api/v1/admin/promotion-history?status=nonsense')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_history_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/promotion-history')->assertUnauthorized();
    }
}
