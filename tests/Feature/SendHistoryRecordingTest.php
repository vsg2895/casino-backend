<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendNewsletterWelcomeEmail;
use App\Models\Newsletter;
use App\Models\PromotionEmailHistory;
use App\Models\Site;
use App\Models\VerificationPromotionSend;
use App\Models\VerifyEmailSend;
use App\Services\VerifyEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * What each send path writes to which history.
 *
 * The rule these tests encode, in one sentence: **a tab shows its own stream,
 * and a send that happened leaves a row.** Both halves had holes.
 *
 *  - The verify email — the first one a subscriber ever gets, and the one that
 *    decides whether they are verified — recorded nothing at all. "It never
 *    arrived" could only be answered from the application log.
 *  - The "Send test" buttons sent an email and left no trace, which is the one
 *    case where a trace is wanted most: the operator is testing precisely
 *    because something is in doubt.
 *
 * And the constraint that shapes the whole thing: a TEST must never be mistaken
 * for a subscriber being mailed, and must never affect a real send. Hence the
 * `is_test` flag, and hence the post-verification test staying out of the
 * shared promotion history — that table drives the 24-hour dedup.
 */
class SendHistoryRecordingTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        [$this->site] = $this->siteWithKey();
    }

    // ── the verify email ─────────────────────────────────────────────────────

    public function test_a_real_verify_send_is_recorded_against_the_subscriber(): void
    {
        $newsletter = Newsletter::create(['site_id' => $this->site->id, 'email' => 'fan@example.test']);

        (new SendNewsletterWelcomeEmail($this->site->id, 'fan@example.test'))
            ->handle(app(VerifyEmailService::class));

        $row = VerifyEmailSend::query()->firstOrFail();

        $this->assertSame('fan@example.test', $row->email);
        $this->assertSame((int) $newsletter->id, (int) $row->newsletter_id);
        $this->assertSame(VerifyEmailSend::STATUS_SUCCESS, $row->status);
        $this->assertFalse((bool) $row->is_test, 'a subscriber send is not a test');
        $this->assertNotNull($row->sent_at);
    }

    public function test_a_failed_verify_send_is_recorded_and_still_fails_the_job(): void
    {
        /*
         * The row must not swallow the exception: a failed send has to keep
         * failing the job so the queue retries it. Recording it is additional,
         * never a replacement.
         */
        Newsletter::create(['site_id' => $this->site->id, 'email' => 'fan@example.test']);

        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('550 mailbox unavailable'));

        try {
            (new SendNewsletterWelcomeEmail($this->site->id, 'fan@example.test'))
                ->handle(app(VerifyEmailService::class));
            $this->fail('the job should have rethrown the transport failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('550', $e->getMessage());
        }

        $row = VerifyEmailSend::query()->firstOrFail();
        $this->assertSame(VerifyEmailSend::STATUS_FAILED, $row->status);
        $this->assertStringContainsString('550', (string) $row->error);
    }

    public function test_the_verify_test_button_records_a_flagged_row(): void
    {
        $this->actingAsAdmin();

        $this->postJson("/api/v1/admin/sites/{$this->site->id}/verify-email/test", [
            'to' => 'operator@example.test',
        ])->assertOk();

        $row = VerifyEmailSend::query()->firstOrFail();

        $this->assertSame('operator@example.test', $row->email);
        $this->assertTrue((bool) $row->is_test);
        // The borrowed subscriber row is NOT credited: nobody asked to verify
        // them, and the history would read as if they had been mailed.
        $this->assertNull($row->newsletter_id);
    }

    public function test_the_verify_history_lists_and_counts_what_was_sent(): void
    {
        $this->actingAsAdmin();
        VerifyEmailSend::create([
            'site_id' => $this->site->id,
            'email'   => 'fan@example.test',
            'status'  => VerifyEmailSend::STATUS_SUCCESS,
            'sent_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/verify-email-sends')
            ->assertOk()
            ->assertJsonPath('data.0.email', 'fan@example.test');

        $this->getJson('/api/v1/admin/verify-email-sends/count')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('failed', 0);
    }

    // ── the post-verification test button ────────────────────────────────────

    public function test_the_post_verification_test_records_itself_without_touching_the_dedup(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/verification-promotion/test', [
            'to' => 'operator@example.test',
        ])->assertOk();

        $row = VerificationPromotionSend::query()->firstOrFail();
        $this->assertSame('operator@example.test', $row->email);
        $this->assertTrue((bool) $row->is_test);

        /*
         * And NOT in the shared promotion history. That table is what the
         * 24-hour dedup reads, so a test written there could make a real
         * subscriber's send get skipped — a test must never change what a
         * campaign does.
         */
        $this->assertSame(0, DB::table('promotion_email_histories')->count());
    }

    // ── deleting one row ─────────────────────────────────────────────────────

    public function test_one_row_can_be_deleted_from_each_history(): void
    {
        $this->actingAsAdmin();

        $verify = VerifyEmailSend::create([
            'site_id' => $this->site->id,
            'email'   => 'fan@example.test',
            'status'  => VerifyEmailSend::STATUS_SUCCESS,
            'sent_at' => now(),
        ]);
        $this->deleteJson("/api/v1/admin/verify-email-sends/{$verify->id}")->assertNoContent();
        $this->assertDatabaseMissing('verify_email_sends', ['id' => $verify->id]);

        $send = VerificationPromotionSend::create([
            'site_id' => $this->site->id,
            'email'   => 'fan@example.test',
            'status'  => VerificationPromotionSend::STATUS_SUCCESS,
            'sent_at' => now(),
        ]);
        $this->deleteJson("/api/v1/admin/verification-promotion/history/{$send->id}")->assertNoContent();
        $this->assertDatabaseMissing('verification_promotion_sends', ['id' => $send->id]);

        // The partitioned one, deleted by (id, sent_date) so MySQL can prune.
        PromotionEmailHistory::recordAttempts(
            $this->site->id,
            ['fan@example.test' => PromotionEmailHistory::STATUS_SUCCESS],
        );
        $id = (int) DB::table('promotion_email_histories')->value('id');
        $this->deleteJson("/api/v1/admin/promotion-history/{$id}")->assertNoContent();
        $this->assertSame(0, DB::table('promotion_email_histories')->count());
    }

    public function test_deleting_a_history_row_that_is_not_there_is_a_404(): void
    {
        // A stale table in one tab must not report a success it did not have.
        $this->actingAsAdmin();

        $this->deleteJson('/api/v1/admin/promotion-history/999999')->assertNotFound();
        $this->deleteJson('/api/v1/admin/verify-email-sends/999999')->assertNotFound();
    }

    public function test_the_history_endpoints_require_an_admin(): void
    {
        $this->getJson('/api/v1/admin/verify-email-sends')->assertUnauthorized();
        $this->deleteJson('/api/v1/admin/verify-email-sends/1')->assertUnauthorized();
    }
}
