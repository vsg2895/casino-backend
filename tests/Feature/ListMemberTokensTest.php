<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendScheduleListBatchJob;
use App\Mail\NewsletterSubscribedMail;
use App\Models\Contracts\TokenAddressable;
use App\Models\EmailSchedule;
use App\Models\MailgunReceiver;
use App\Models\Newsletter;
use App\Models\SendgridReceiver;
use App\Models\Site;
use App\Models\WarmupEmail;
use App\Services\Mail\PromotionMailerFactory;
use App\Services\PromotionEmailService;
use App\Services\ScheduleRecipientService;
use Database\Seeders\ReceiverUnsubscribeTokenSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Every address on a GLOBAL list carries its own unsubscribe token, the way a
 * subscriber does.
 *
 * Until 2026-12 only the two receiver lists did, and the warmup list had none
 * at all — its messages carried an inert link. That is what locked the
 * subscription template to the newsletters audience: it is built from the
 * recipient's own token, so on any other list its opt-out would have resolved
 * to nothing.
 *
 * These tests pin the three things that had to become true for that
 * restriction to be lifted: every row HAS a token, the opt-out link built from
 * it RESOLVES, and the subscriber path is unchanged. They also pin what did NOT
 * change — the verify template is still newsletters-only, because confirming an
 * address is a claim only the person who submitted it can make, and a token
 * does not create one.
 */
class ListMemberTokensTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    /** @param array<string, mixed> $attrs */
    private function schedule(Site $site, string $audience, string $template, array $attrs = []): EmailSchedule
    {
        return EmailSchedule::create([
            'site_id'       => $site->id,
            'name'          => 'Nightly',
            'audience'      => $audience,
            'template'      => $template,
            'limit'         => 50,
            'cooldown_days' => 1,
            'frequency'     => EmailSchedule::FREQ_DAILY,
            'time'          => '03:00',
            'active'        => true,
            ...$attrs,
        ]);
    }

    // ── Every row has a token ───────────────────────────────────────────────

    public function test_every_list_mints_a_token_when_a_row_is_created(): void
    {
        $rows = [
            MailgunReceiver::create(['email' => 'a@example.com', 'source' => 'manual']),
            SendgridReceiver::create(['email' => 'b@example.com', 'source' => 'manual']),
            WarmupEmail::create(['email' => 'c@example.com']),
        ];

        foreach ($rows as $row) {
            $this->assertSame(64, strlen($row->unsubscribeToken()), $row::class . ' created a row with no token');
        }
    }

    public function test_tokens_are_unique_across_a_list(): void
    {
        $tokens = collect(range(1, 20))
            ->map(static fn (int $i): string => WarmupEmail::create(['email' => "seed{$i}@example.com"])->unsubscribeToken());

        $this->assertCount(20, $tokens->unique(), 'two addresses were given the same opt-out credential');
    }

    /** The import writes rows with a bulk insert, which fires no model events. */
    public function test_the_warmup_import_sets_a_token_itself(): void
    {
        $service = app(\App\Services\WarmupImportService::class);
        $write = new \ReflectionMethod($service, 'writeBatch');

        $write->invoke($service, ['imported-a@example.com', 'imported-b@example.com']);

        $this->assertSame(
            0,
            WarmupEmail::query()->missingUnsubscribeToken()->count(),
            'a bulk import wrote addresses that can never unsubscribe',
        );
    }

    // ── The backfill ────────────────────────────────────────────────────────

    public function test_the_seeder_fills_only_what_is_missing_and_is_idempotent(): void
    {
        $untouched = MailgunReceiver::create(['email' => 'has@example.com', 'source' => 'manual']);
        $before = $untouched->unsubscribeToken();

        // A row as it exists in production before the backfill: written around
        // the model, so no token was ever minted.
        DB::table('warmup_emails')->insert([
            'email' => 'legacy@example.com', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seed(ReceiverUnsubscribeTokenSeeder::class);

        $legacy = WarmupEmail::where('email', 'legacy@example.com')->sole();
        $this->assertSame(64, strlen($legacy->unsubscribeToken()), 'the backfill left a row without a token');
        $this->assertSame($before, $untouched->fresh()->unsubscribeToken(), 'the backfill rewrote a token that existed');

        // A second run must change nothing at all.
        $this->seed(ReceiverUnsubscribeTokenSeeder::class);
        $this->assertSame($legacy->unsubscribeToken(), $legacy->fresh()->unsubscribeToken());
    }

    public function test_the_seeder_gives_every_backfilled_row_a_different_token(): void
    {
        foreach (range(1, 10) as $i) {
            DB::table('warmup_emails')->insert([
                'email' => "legacy{$i}@example.com", 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->seed(ReceiverUnsubscribeTokenSeeder::class);

        $this->assertCount(10, DB::table('warmup_emails')->pluck('unsubscribe_token')->unique());
    }

    // ── The templates the tokens unlocked ───────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function listAudiences(): array
    {
        return [
            'mailgun receivers'  => [EmailSchedule::AUDIENCE_MAILGUN_RECEIVERS, MailgunReceiver::class],
            'sendgrid receivers' => [EmailSchedule::AUDIENCE_SENDGRID_RECEIVERS, SendgridReceiver::class],
            'warmup'             => [EmailSchedule::AUDIENCE_WARMUP, WarmupEmail::class],
        ];
    }

    /** @param class-string<TokenAddressable> $model */
    #[DataProvider('listAudiences')]
    public function test_the_subscription_template_reaches_a_list_and_carries_that_list_opt_out(
        string $audience,
        string $model,
    ): void {
        Mail::fake();
        [$site] = $this->siteWithKey();
        $row = $this->member($model);

        $this->runBatch($this->schedule($site, $audience, EmailSchedule::TEMPLATE_SUBSCRIBE), $audience, $row->id);

        Mail::assertSent(NewsletterSubscribedMail::class, function (NewsletterSubscribedMail $mail) use ($row): bool {
            // The opt-out must point at the list that actually mailed them —
            // never at a site newsletter they were never on.
            return $mail->hasTo($row->email) && str_contains($mail->render(), $row->unsubscribeUrl());
        });
    }

    /**
     * The verify template is the one a list CANNOT be sent — it asks for
     * confirmation of an address the recipient submitted, and nobody on an
     * operator-built list submitted one. Refused at save time, so the shape
     * never reaches a send.
     *
     * @param  class-string<TokenAddressable>  $model  unused; the provider is
     *         shared with the sending tests above
     */
    #[DataProvider('listAudiences')]
    public function test_the_verify_template_is_refused_for_a_list_audience(string $audience, string $model): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/schedules', [
            'site_id'       => $site->id,
            'template'      => EmailSchedule::TEMPLATE_VERIFY,
            'audience'      => $audience,
            'limit'         => 100,
            'cooldown_days' => 1,
            'frequency'     => EmailSchedule::FREQ_DAILY,
            'time'          => '03:00',
            'provider'      => EmailSchedule::PROVIDER_SMTP,
        ])->assertStatus(422)->assertJsonValidationErrors('audience');
    }

    /** The established path must be untouched by all of the above. */
    public function test_a_subscriber_still_verifies_exactly_as_before(): void
    {
        [$site] = $this->siteWithKey();
        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'ann@example.com', 'full_name' => 'Ann']);

        $this->postJson('/api/v1/verify/' . $subscriber->unsubscribe_token)->assertOk();

        $fresh = $subscriber->fresh();
        $this->assertTrue((bool) $fresh->verified);
        $this->assertNotNull($fresh->verified_at);
    }

    // ── The warmup opt-out the token made possible ──────────────────────────

    public function test_a_warmup_address_can_unsubscribe_and_leaves_every_audience(): void
    {
        [$site] = $this->siteWithKey();
        $seed = WarmupEmail::create(['email' => 'seed@example.com']);
        $schedule = $this->schedule($site, EmailSchedule::AUDIENCE_WARMUP, EmailSchedule::TEMPLATE_PROMOTION);

        $this->assertContains('seed@example.com', $this->audienceEmails($schedule));

        $this->postJson('/api/v1/warmup-unsubscribe/' . $seed->unsubscribeToken())->assertOk();

        $this->assertNotNull($seed->fresh()->unsubscribed_at);
        $this->assertNotContains('seed@example.com', $this->audienceEmails($schedule));
        $this->assertSame(0, app(\App\Services\WarmupRecipientService::class)->available());
    }

    /** GET renders the confirm page and must change nothing — scanners prefetch it. */
    public function test_the_warmup_opt_out_page_does_not_act(): void
    {
        $seed = WarmupEmail::create(['email' => 'seed@example.com']);

        $this->get('/api/v1/warmup-unsubscribe/' . $seed->unsubscribeToken())->assertOk();

        $this->assertNull($seed->fresh()->unsubscribed_at);
    }

    /** An unknown token must not reveal itself — no 404 to probe with. */
    public function test_an_unknown_warmup_token_is_answered_the_same_way(): void
    {
        $this->postJson('/api/v1/warmup-unsubscribe/' . str_repeat('a', 64))->assertOk();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param class-string<TokenAddressable> $model */
    private function member(string $model): TokenAddressable
    {
        return $model === WarmupEmail::class
            ? WarmupEmail::create(['email' => 'member@example.com'])
            : $model::create(['email' => 'member@example.com', 'name' => 'Ann', 'source' => 'manual']);
    }

    private function runBatch(EmailSchedule $schedule, string $audience, int $rowId): void
    {
        (new SendScheduleListBatchJob(
            $schedule->id, (int) $schedule->site_id, $audience, [$rowId], EmailSchedule::PROVIDER_SMTP, null,
        ))->handle(app(PromotionEmailService::class), app(PromotionMailerFactory::class));
    }

    /** @return array<int, string> */
    private function audienceEmails(EmailSchedule $schedule): array
    {
        return app(ScheduleRecipientService::class)->sample($schedule, 50)->pluck('email')->all();
    }
}
