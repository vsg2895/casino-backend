<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Newsletter;
use App\Models\Site;
use App\Services\Validation\SendGridEmailValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Validation runs on the public subscribe endpoint and the admin's ad-hoc
 * check tool — and NOWHERE ELSE.
 *
 * This is the main regression risk of the feature: a validation call added to a
 * bulk path would burn the entire 2,500-credit monthly budget in one campaign
 * run, and would do it silently. These tests exist to fail loudly the moment a
 * second caller appears.
 *
 * Two complementary checks:
 *   1. behavioural — exercise the other paths and assert no HTTP call is made;
 *   2. structural  — assert the service has exactly one caller in app/.
 *
 * The second matters because a new call site added to a path with no test would
 * otherwise slip through.
 */
class EmailValidationScopeTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sendgrid_validation.enabled' => true,
            'services.sendgrid_validation.key'     => 'SG.test-key',
        ]);
    }

    public function test_admin_created_subscribers_never_trigger_validation(): void
    {
        Http::fake();
        $this->actingAsAdmin();
        [$site] = $this->siteWithKey();

        $this->postJson('/api/v1/admin/newsletters', [
            'site_id' => $site->id,
            'email'   => 'manual@example.com',
        ])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseCount('email_validation_logs', 0);
        $this->assertDatabaseHas('newsletters', ['email' => 'manual@example.com']);
    }

    public function test_the_unsubscribe_endpoint_never_triggers_validation(): void
    {
        Http::fake();
        [$site, $key] = $this->siteWithKey();

        $subscriber = Newsletter::create(['site_id' => $site->id, 'email' => 'x@example.com']);

        $this->postJson(
            $this->publicBase($site) . '/newsletter/unsubscribe',
            ['token' => $subscriber->fresh()->unsubscribe_token],
            $this->siteHeaders($key),
        )->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseCount('email_validation_logs', 0);
    }

    /**
     * The structural guard: who can SPEND a credit.
     *
     * Asserting on references to the class is not the right property — the stats
     * controller legitimately injects it for the read-only quota accessors
     * (usedThisMonth, reportedRateLimit), and neither costs anything. What must
     * stay unique is the call to validate(), which is the only method that
     * reaches SendGrid and the only one that bills.
     *
     * If this count changes, a second path started spending credits.
     */
    public function test_only_one_place_in_the_application_calls_validate(): void
    {
        // `->validate(` alone is far too broad — it matches Laravel's own
        // $request->validate() in every controller. A file only spends a credit
        // if it BOTH knows the service AND calls validate() on something.
        $callers = array_values(array_intersect(
            $this->filesContaining('SendGridEmailValidationService', skip: 'SendGridEmailValidationService.php'),
            $this->filesContaining('->validate(', skip: 'SendGridEmailValidationService.php'),
        ));

        $this->assertSame(
            [
                // The admin's deliberate, operator-triggered single-address
                // check. Added knowingly: it spends a credit per click, so it is
                // throttled and reuses the cache/cooldown/quota controls.
                'Http/Controllers/Api/Admin/EmailValidationCheckController.php',
                // The public subscribe gate.
                'Services/Validation/SubscribeValidationGate.php',
            ],
            $callers,
            'Something new calls SendGridEmailValidationService::validate(). '
            . 'Only the public subscribe gate and the admin check tool may spend credits.',
        );
    }

    /**
     * And the set of files that so much as MENTION the service is fixed.
     *
     * Broader than the call check above, so a new injection is noticed even
     * before it is used — the cheap moment to catch it.
     */
    public function test_the_service_is_referenced_by_exactly_two_known_files(): void
    {
        $this->assertSame(
            [
                // spends credits: the operator-triggered single-address check
                'Http/Controllers/Api/Admin/EmailValidationCheckController.php',
                // read-only: quota counters for the admin widget
                'Http/Controllers/Api/Admin/EmailValidationStatsController.php',
                // spends credits: the public subscribe gate
                'Services/Validation/SubscribeValidationGate.php',
            ],
            $this->filesContaining('SendGridEmailValidationService', skip: 'SendGridEmailValidationService.php'),
        );
    }

    /**
     * Application files containing $needle, relative to app/.
     *
     * @return list<string>
     */
    private function filesContaining(string $needle, string $skip): array
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), $skip)) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $hits[] = str_replace(app_path() . '/', '', $file->getPathname());
            }
        }

        sort($hits);

        return $hits;
    }

    /** And the gate itself is reachable from exactly one controller. */
    public function test_the_gate_has_exactly_one_caller_in_the_application(): void
    {
        $callers = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            if (str_contains($file->getPathname(), 'SubscribeValidationGate.php')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), 'SubscribeValidationGate')) {
                $callers[] = str_replace(app_path() . '/', '', $file->getPathname());
            }
        }

        sort($callers);

        $this->assertSame(['Http/Controllers/Api/Public/NewsletterController.php'], $callers);
    }

    public function test_the_service_is_never_constructed_by_bulk_or_warmup_code(): void
    {
        // Belt and braces on the paths the brief names explicitly.
        foreach ([
            'Jobs',
            'Services/PromotionEmailService.php',
            'Services/PostVerificationPromotionEmailService.php',
            'Services/Mail',
            'Console/Commands',
        ] as $path) {
            $full = app_path($path);

            if (! file_exists($full)) {
                continue;
            }

            $hits = [];
            $iter = is_dir($full)
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full))
                : [new \SplFileInfo($full)];

            foreach ($iter as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), 'SendGridEmailValidationService')) {
                    $hits[] = $file->getPathname();
                }
            }

            $this->assertSame([], $hits, "{$path} must never call the validation service");
        }
    }

    public function test_the_service_can_be_resolved_without_a_key_and_does_not_throw(): void
    {
        Http::fake();
        config(['services.sendgrid_validation.key' => '']);

        $result = app(SendGridEmailValidationService::class)->validate('x@example.com', 'winpalack');

        $this->assertFalse($result->hasVerdict());
        $this->assertTrue($result->wasSkipped);
        $this->assertSame('missing_key', $result->skipReason);
        Http::assertNothingSent();
    }
}
