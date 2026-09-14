<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailValidationLog;
use App\Models\Site;
use App\Support\Validation\ValidationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The admin stats surface: quota thresholds and the per-site breakdown.
 *
 * The thresholds are the part worth pinning — an off-by-one at 80% or 100% is
 * invisible until the month a plan actually runs out, which is the one month it
 * matters.
 */
class EmailValidationStatsTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function billed(Site $site, int $n): void
    {
        foreach (range(1, $n) as $i) {
            EmailValidationLog::create([
                'site_id' => $site->id, 'email' => "u{$i}@example.com",
                'source' => 'subscribe_' . $site->slug,
                'outcome' => ValidationOutcome::Allowed->value, 'verdict' => 'Valid', 'score' => 0.9,
                'was_cached' => false,
                'quota_month' => EmailValidationLog::currentQuotaMonth(),
            ]);
        }
    }

    private function stats(): array
    {
        $this->actingAsAdmin();

        return $this->getJson('/api/v1/admin/email-validation/stats')->assertOk()->json();
    }

    public function test_the_warning_fires_at_80_percent_and_not_before(): void
    {
        [$site] = $this->siteWithKey();
        config(['services.sendgrid_validation.monthly_quota' => 100]);

        $this->billed($site, 79);
        $quota = $this->stats()['quota'];
        $this->assertFalse($quota['warning'], '79% must not warn');

        $this->billed($site, 1); // 80
        $quota = $this->stats()['quota'];
        $this->assertTrue($quota['warning'], '80% must warn');
        $this->assertFalse($quota['exhausted']);
        $this->assertSame(80, $quota['percent']);
        $this->assertSame(20, $quota['remaining']);
    }

    public function test_exhaustion_replaces_the_warning_at_100_percent(): void
    {
        [$site] = $this->siteWithKey();
        config(['services.sendgrid_validation.monthly_quota' => 10]);

        $this->billed($site, 10);
        $quota = $this->stats()['quota'];

        $this->assertTrue($quota['exhausted']);
        // Not both: the banner must say "stopped", not "getting close".
        $this->assertFalse($quota['warning']);
        $this->assertSame(0, $quota['remaining']);
    }

    public function test_cache_hits_and_skips_do_not_count_against_the_quota(): void
    {
        [$site] = $this->siteWithKey();
        config(['services.sendgrid_validation.monthly_quota' => 100]);

        $this->billed($site, 2);

        foreach ([
            ['was_cached' => true, 'reason_code' => null],
            // A skip is now a failed_open carrying the reason it never called.
            ['was_cached' => false, 'reason_code' => 'quota_exhausted'],
        ] as $flags) {
            EmailValidationLog::create([
                'site_id' => $site->id, 'email' => 'free@example.com', 'source' => 'subscribe_x',
                'outcome' => ValidationOutcome::FailedOpen->value,
                'quota_month' => EmailValidationLog::currentQuotaMonth(),
                ...$flags,
            ]);
        }

        $data = $this->stats();
        $this->assertSame(2, $data['quota']['used'], 'only real API calls are billed');
        $this->assertSame(4, $data['totals']['attempts']);
        $this->assertSame(2, $data['totals']['api_calls']);
        $this->assertSame(1, $data['totals']['cache_hits']);
        $this->assertSame(1, $data['totals']['skipped']);
    }

    public function test_every_registered_site_appears_even_with_no_attempts(): void
    {
        $this->siteWithKey();
        $this->siteWithKey();
        [$third] = $this->siteWithKey();
        $this->billed($third, 1);

        $sites = $this->stats()['sites'];

        // A missing row reads as "no data" when the answer is "nobody
        // subscribed there" — different problems, so all sites are listed.
        $this->assertCount(3, $sites);
        $this->assertSame([0, 0, 1], array_column($sites, 'attempts'));
    }

    public function test_the_stats_endpoint_never_returns_the_key(): void
    {
        $this->siteWithKey();
        config(['services.sendgrid_validation.key' => 'SG.super-secret-value']);

        $this->actingAsAdmin();
        $body = $this->getJson('/api/v1/admin/email-validation/stats')->assertOk()->getContent();

        $this->assertStringNotContainsString('SG.super-secret', $body);
        $this->assertStringNotContainsString('super-secret', $body);
        // Only whether one is configured.
        $this->assertTrue($this->stats()['quota']['key_configured']);
    }

    public function test_the_endpoints_require_admin_authentication(): void
    {
        $this->getJson('/api/v1/admin/email-validation/stats')->assertUnauthorized();
        $this->getJson('/api/v1/admin/email-validation')->assertUnauthorized();
        $this->getJson('/api/v1/admin/email-validation/export')->assertUnauthorized();
    }
}
