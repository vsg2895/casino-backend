<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Validation\SendGridEmailValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The wire contract with SendGrid, pinned.
 *
 * The endpoint, the auth scheme, the request body and the shape we parse out of
 * the response are all things a refactor can quietly break without any test
 * failing — the feature would just start failing open on every subscribe and
 * look like an outage. These assertions make that loud.
 */
class EmailValidationContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.sendgrid_validation.enabled' => true,
            'services.sendgrid_validation.key'     => 'SG.abc',
            'services.sendgrid_validation.timeout' => 3,
        ]);
        Cache::flush();
    }

    private function fakeFullResponse(): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response([
            'result' => [
                'verdict' => 'Valid',
                'score'   => 0.91,
                'checks'  => [
                    'domain'     => ['has_valid_address_syntax' => true, 'has_mx_or_a_record' => true, 'is_suspected_disposable_address' => false],
                    'local_part' => ['is_suspected_role_address' => true],
                    'additional' => ['has_known_bounces' => false, 'has_suspected_bounces' => true],
                ],
                'suggestion' => 'gmail.com',
            ],
        ], 200, ['X-RateLimit-Remaining' => '2431', 'X-RateLimit-Reset' => '1793404800'])]);
    }

    /**
     * The rule itself, not one example of it.
     *
     * The literal assertion above would still pass if someone added a slug whose
     * punctuation survives into `source`. This covers the shapes real slugs
     * take — kebab-case above all, which is the project's own convention and
     * would have failed exactly like the underscore did.
     */
    #[DataProvider('slugShapes')]
    public function test_the_outbound_source_never_violates_sendgrids_rule(string $slug): void
    {
        $this->fakeFullResponse();

        app(SendGridEmailValidationService::class)->validate('person@example.com', $slug);

        Http::assertSent(function ($request) use ($slug): bool {
            $source = $request->data()['source'];

            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9 ]+$/',
                $source,
                "source '{$source}' for slug '{$slug}' would be rejected by SendGrid with a 400.",
            );
            $this->assertSame(trim($source), $source, 'source must not be padded.');

            return true;
        });
    }

    /** @return array<string, array{0: string}> */
    public static function slugShapes(): array
    {
        return [
            'plain'            => ['winpalack'],
            'kebab-case'       => ['site-template'],
            'underscored'      => ['some_site'],
            'digits'           => ['casino24'],
            'multiple hyphens' => ['a-b-c'],
        ];
    }

    public function test_it_posts_the_documented_request(): void
    {
        $this->fakeFullResponse();

        app(SendGridEmailValidationService::class)->validate('person@exmaple.com', 'winpalack');

        Http::assertSent(function ($request): bool {
            $this->assertSame('https://api.sendgrid.com/v3/validations/email', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame('Bearer SG.abc', $request->header('Authorization')[0] ?? '');

            $body = $request->data();
            $this->assertSame('person@exmaple.com', $body['email']);
            // The site slug travels in `source` so usage is segmentable inside
            // SendGrid's own dashboard, not just in our logs.
            //
            // SPACE, not underscore. SendGrid answers 400 to anything else:
            // "source names can only contain spaces and alphanumeric
            // characters". `subscribe_winpalack` was rejected on every single
            // call, and because the old code recorded only 'http_400' the reason
            // never reached the log.
            $this->assertSame('subscribe winpalack', $body['source']);
            $this->assertSame(['email', 'source'], array_keys($body));

            return true;
        });
    }

    public function test_it_parses_every_documented_field(): void
    {
        $this->fakeFullResponse();

        $result = app(SendGridEmailValidationService::class)->validate('person@exmaple.com', 'winpalack');

        $this->assertSame('Valid', $result->verdict);
        $this->assertSame(0.91, $result->score);
        $this->assertSame('gmail.com', $result->suggestion);
        $this->assertNotNull($result->latencyMs);

        // Every check named in the brief survives the round trip.
        $this->assertTrue($result->checks['domain']['has_valid_address_syntax']);
        $this->assertTrue($result->checks['domain']['has_mx_or_a_record']);
        $this->assertFalse($result->checks['domain']['is_suspected_disposable_address']);
        $this->assertTrue($result->checks['local_part']['is_suspected_role_address']);
        $this->assertFalse($result->checks['additional']['has_known_bounces']);
        $this->assertTrue($result->checks['additional']['has_suspected_bounces']);
    }

    public function test_it_persists_sendgrids_own_reported_balance(): void
    {
        $this->fakeFullResponse();
        $service = app(SendGridEmailValidationService::class);

        $service->validate('person@exmaple.com', 'winpalack');

        // Kept because our local count and SendGrid's can legitimately differ.
        $this->assertSame(2431, $service->reportedRateLimit()['remaining']);
        $this->assertSame(1793404800, $service->reportedRateLimit()['reset']);
    }

    public function test_a_timeout_is_never_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
        });

        $result = app(SendGridEmailValidationService::class)->validate('a@b.test', 'winpalack');

        // A retry would double the visitor's wait AND risk a second charge for a
        // request SendGrid may already have processed.
        $this->assertFalse($result->hasVerdict());
        $this->assertLessThanOrEqual(2, $attempts, 'a timeout must not be retried repeatedly');
    }

    public function test_the_key_never_appears_in_a_stored_error_message(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('failed with token SG.abc.SUPERSECRETVALUE in header');
        });

        $result = app(SendGridEmailValidationService::class)->validate('a@b.test', 'winpalack');

        $this->assertStringNotContainsString('SUPERSECRET', (string) $result->errorMessage);
        $this->assertStringContainsString('[redacted]', (string) $result->errorMessage);
    }

    public function test_allowed_verdicts_come_from_config_and_are_case_insensitive(): void
    {
        $service = app(SendGridEmailValidationService::class);

        config(['services.sendgrid_validation.allowed_verdicts' => ['Valid']]);
        $this->assertTrue($service->isAllowed('Valid'));
        $this->assertFalse($service->isAllowed('Risky'));

        // Widening is an env change, never a code change.
        config(['services.sendgrid_validation.allowed_verdicts' => ['valid', 'RISKY']]);
        $this->assertTrue($service->isAllowed('Risky'));
        $this->assertFalse($service->isAllowed('Invalid'));
    }

    public function test_a_repeated_address_is_capped_by_the_per_email_cooldown(): void
    {
        // Successful verdicts are cached, so THIS is the hole the cooldown
        // exists for: failures are deliberately not cached (caching an outage
        // would leave days of unvalidated subscribes), so without a cooldown a
        // script looping one address during a SendGrid outage would spend a
        // credit on every pass.
        Http::fake(['api.sendgrid.com/*' => Http::response([], 500)]);
        config(['services.sendgrid_validation.email_cooldown_seconds' => 60]);

        $service = app(SendGridEmailValidationService::class);

        $first = $service->validate('loop@example.com', 'winpalack');
        $second = $service->validate('loop@example.com', 'winpalack');
        $third = $service->validate('loop@example.com', 'winpalack');

        Http::assertSentCount(1);

        $this->assertNull($first->skipReason);
        $this->assertSame('email_cooldown', $second->skipReason);
        $this->assertSame('email_cooldown', $third->skipReason);

        // And a cooled-down attempt still has no verdict, so the caller fails
        // OPEN: the cooldown caps spend, it never costs anyone a subscription.
        $this->assertFalse($second->hasVerdict());
    }

    public function test_the_cooldown_is_per_address_not_global(): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response([], 500)]);
        config(['services.sendgrid_validation.email_cooldown_seconds' => 60]);

        $service = app(SendGridEmailValidationService::class);
        $service->validate('one@example.com', 'winpalack');
        $second = $service->validate('two@example.com', 'winpalack');

        // A different address must not be punished for the first one.
        Http::assertSentCount(2);
        $this->assertNull($second->skipReason);
    }

    public function test_the_cooldown_can_be_switched_off(): void
    {
        Http::fake(['api.sendgrid.com/*' => Http::response([], 500)]);
        config(['services.sendgrid_validation.email_cooldown_seconds' => 0]);

        $service = app(SendGridEmailValidationService::class);
        $service->validate('off@example.com', 'winpalack');
        $service->validate('off@example.com', 'winpalack');

        Http::assertSentCount(2);
    }

    public function test_the_env_string_is_parsed_into_an_array_by_config(): void
    {
        // The controller and service must never see a comma-separated string.
        $this->assertIsArray(config('services.sendgrid_validation.allowed_verdicts'));
    }
}
