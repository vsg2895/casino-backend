<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPromotionBatchJob;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Site;
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
            ->handle(app(PromotionEmailService::class));

        $this->assertDatabaseCount('promotion_email_histories', 2);
        $this->assertDatabaseHas('promotion_email_histories', [
            'site_id'   => $site->id,
            'email'     => 'a@example.com',
            'sent_date' => now()->toDateString(),
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
            ->handle(app(PromotionEmailService::class));

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

    public function test_failed_recipient_is_not_written_to_history(): void
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

        (new SendPromotionBatchJob($site->id, ['bad@example.com', 'good@example.com']))->handle($service);

        $this->assertDatabaseHas('promotion_email_histories', ['email' => 'good@example.com']);
        $this->assertDatabaseMissing('promotion_email_histories', ['email' => 'bad@example.com']);
    }

    public function test_addresses_skipped_by_dedup_are_not_re_added_to_history(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey();
        Newsletter::create(['site_id' => $site->id, 'email' => 'dup@example.com']);
        $service = app(PromotionEmailService::class);

        (new SendPromotionBatchJob($site->id, ['dup@example.com']))->handle($service);
        (new SendPromotionBatchJob($site->id, ['dup@example.com']))->handle($service); // same day

        $this->assertDatabaseCount('promotion_email_histories', 1);
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

    public function test_history_requires_auth(): void
    {
        $this->getJson('/api/v1/admin/promotion-history')->assertUnauthorized();
    }
}
