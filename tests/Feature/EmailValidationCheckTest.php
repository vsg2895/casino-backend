<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailValidationLog;
use App\Models\Newsletter;
use App\Support\Validation\ValidationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The admin's ad-hoc "Validate Email" tool.
 *
 * It is the SECOND sanctioned caller of the validation service, so the things
 * worth proving are that it spends credits only when it must, that it never
 * touches subscriber data, and that it reports "could not check" honestly
 * rather than inventing a verdict.
 */
class EmailValidationCheckTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sendgrid_validation.enabled'          => true,
            'services.sendgrid_validation.key'              => 'SG.test',
            'services.sendgrid_validation.allowed_verdicts' => ['Valid'],
            'services.sendgrid_validation.monthly_quota'    => 2500,
            'services.sendgrid_validation.email_cooldown_seconds' => 0,
        ]);
        Cache::flush();
    }

    private function fake(string $verdict, float $score = 0.9): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response(['result' => [
            'verdict' => $verdict, 'score' => $score,
            'checks' => [
                'domain' => ['has_valid_address_syntax' => true, 'has_mx_or_a_record' => true, 'is_suspected_disposable_address' => false],
                'local_part' => ['is_suspected_role_address' => true],
                'additional' => ['has_known_bounces' => false, 'has_suspected_bounces' => false],
            ],
            'suggestion' => 'gmail.com',
        ]], 200)]);
    }

    private function check(int $siteId, string $email = 'someone@example.com')
    {
        $this->actingAsAdmin();

        return $this->postJson('/api/v1/admin/email-validation/check', ['email' => $email, 'site_id' => $siteId]);
    }

    public function test_it_requires_admin_authentication(): void
    {
        $this->postJson('/api/v1/admin/email-validation/check', ['email' => 'a@b.test', 'site_id' => 1])
            ->assertUnauthorized();
    }

    public function test_it_validates_its_input(): void
    {
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/email-validation/check', ['site_id' => $site->id])->assertStatus(422);
        $this->postJson('/api/v1/admin/email-validation/check', ['email' => 'nope', 'site_id' => $site->id])->assertStatus(422);
        // A site is required so the credit is attributable in the stats.
        $this->postJson('/api/v1/admin/email-validation/check', ['email' => 'a@b.test'])->assertStatus(422);
        $this->postJson('/api/v1/admin/email-validation/check', ['email' => 'a@b.test', 'site_id' => 99999])->assertStatus(422);
    }

    public function test_it_returns_the_full_result(): void
    {
        [$site] = $this->siteWithKey();
        $this->fake('Valid', 0.93);

        $r = $this->check($site->id)->assertOk();

        $r->assertJsonPath('checked', true);
        $r->assertJsonPath('verdict', 'Valid');
        $r->assertJsonPath('score', 0.93);
        $r->assertJsonPath('would_allow', true);
        $r->assertJsonPath('suggestion', 'gmail.com');
        $r->assertJsonPath('checks.domain.has_mx_or_a_record', true);
        $r->assertJsonPath('checks.local_part.is_suspected_role_address', true);
        $r->assertJsonPath('checks.additional.has_known_bounces', false);
        $r->assertJsonPath('allowed_verdicts', ['Valid']);
        $this->assertNotNull($r->json('latency_ms'));
    }

    public function test_a_rejected_verdict_reports_that_the_form_would_refuse_it(): void
    {
        [$site] = $this->siteWithKey();
        $this->fake('Invalid', 0.01);

        $this->check($site->id, 'bad@example.com')->assertOk()->assertJsonPath('would_allow', false);
    }

    public function test_widening_the_allow_list_changes_the_answer_without_a_code_change(): void
    {
        // A SEPARATE test rather than a second call in the one above: calling
        // Http::fake() again does not replace an existing stub for the same
        // pattern, so the first response keeps being served and the assertion
        // silently tests nothing.
        [$site] = $this->siteWithKey();
        config(['services.sendgrid_validation.allowed_verdicts' => ['Valid', 'Risky']]);
        $this->fake('Risky', 0.88);

        $this->check($site->id, 'risky@example.com')->assertOk()->assertJsonPath('would_allow', true);
    }

    public function test_it_never_creates_or_touches_a_subscriber(): void
    {
        Queue::fake();
        [$site] = $this->siteWithKey();
        $this->fake('Valid');

        $this->check($site->id)->assertOk();

        // A test tool must not sign anyone up, and must not send anything.
        $this->assertDatabaseCount('newsletters', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_is_logged_with_a_distinguishable_source(): void
    {
        [$site] = $this->siteWithKey();
        $this->fake('Valid');

        $this->check($site->id)->assertOk();

        $log = EmailValidationLog::firstOrFail();
        // Not `subscribe_…`: an operator testing addresses must not inflate a
        // site's apparent signup validation numbers.
        $this->assertSame('admin_check_' . $site->slug, $log->source);
        $this->assertSame(ValidationOutcome::Allowed->value, $log->outcome);
        $this->assertSame($site->id, $log->site_id);
    }

    public function test_a_cached_verdict_costs_nothing_and_says_so(): void
    {
        [$site] = $this->siteWithKey();
        $this->fake('Valid');

        $this->check($site->id)->assertOk()->assertJsonPath('was_cached', false);
        $this->check($site->id)->assertOk()->assertJsonPath('was_cached', true);

        Http::assertSentCount(1);
        $this->assertSame(1, EmailValidationLog::query()->billed()->count());
    }

    public function test_an_exhausted_quota_reports_honestly_instead_of_guessing(): void
    {
        Http::fake();
        [$site] = $this->siteWithKey();
        config(['services.sendgrid_validation.monthly_quota' => 1]);

        EmailValidationLog::create([
            'site_id' => $site->id, 'email' => 'prior@example.com', 'source' => 'subscribe_x',
            'outcome' => ValidationOutcome::Allowed->value, 'verdict' => 'Valid',
            'was_cached' => false,
            'quota_month' => EmailValidationLog::currentQuotaMonth(),
        ]);

        $r = $this->check($site->id)->assertOk();

        Http::assertNothingSent();
        // No verdict invented, and would_allow stays NULL so the UI cannot
        // render a decision that was never made.
        $r->assertJsonPath('checked', false);
        $r->assertJsonPath('verdict', null);
        $r->assertJsonPath('would_allow', null);
        $r->assertJsonPath('reason_code', 'quota_exhausted');
    }

    public function test_a_failed_call_reports_the_error_without_the_key(): void
    {
        [$site] = $this->siteWithKey();
        Http::fake(function () {
            throw new \RuntimeException('bad token SG.abc.SECRETMATERIAL');
        });

        $r = $this->check($site->id)->assertOk();

        $r->assertJsonPath('checked', false);
        $r->assertJsonPath('would_allow', null);
        $this->assertStringNotContainsString('SECRETMATERIAL', $r->getContent());
        $this->assertStringContainsString('[redacted]', (string) $r->json('error_message'));
    }
}
