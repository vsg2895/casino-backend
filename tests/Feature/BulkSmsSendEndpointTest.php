<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendBulkSmsJob;
use App\Models\NewsletterBasedOnPhone;
use App\Models\TwilioConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The exact bodies and status codes of `POST newsletter-phones/send`.
 *
 * The fan-out job is faked throughout: no request in this file can reach Twilio.
 */
class BulkSmsSendEndpointTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function config(array $attrs = []): TwilioConfig
    {
        return TwilioConfig::create([
            'name' => 'Main',
            'account_sid' => 'AC'.str_repeat('0', 32),
            'auth_token' => 'token',
            'from_number' => '+15005550006',
            'status' => 'active',
            ...$attrs,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function send(TwilioConfig $config, array $payload = [])
    {
        return $this->postJson('/api/v1/admin/newsletter-phones/send', [
            'twilio_config_id' => $config->id,
            'body' => 'Hello',
            ...$payload,
        ]);
    }

    public function test_a_config_without_a_sender_is_refused(): void
    {
        $this->actingAsAdmin();
        NewsletterBasedOnPhone::create(['phone' => '+15005550001']);

        $this->send($this->config(['from_number' => null]))->assertStatus(422)->assertExactJson([
            'ok' => false,
            'message' => '"Main" has no sender configured. Add a Twilio phone number or a Messaging Service SID first.',
        ]);
        Bus::assertNotDispatched(SendBulkSmsJob::class);
    }

    public function test_an_empty_audience_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->send($this->config())->assertStatus(422)->assertExactJson([
            'ok' => false,
            'message' => 'No numbers match the selected filters, so nothing was queued.',
        ]);
        Bus::assertNotDispatched(SendBulkSmsJob::class);
    }

    public function test_a_second_run_is_refused_while_the_lock_is_held(): void
    {
        $this->actingAsAdmin();
        NewsletterBasedOnPhone::create(['phone' => '+15005550001']);
        Cache::lock(SendBulkSmsJob::runLockKey(), 900)->get();

        $this->send($this->config())->assertStatus(409)->assertExactJson([
            'ok' => false,
            'message' => 'A bulk SMS run is already in progress. Wait for it to finish before starting another.',
        ]);
        Bus::assertNotDispatched(SendBulkSmsJob::class);
    }

    public function test_a_run_is_queued(): void
    {
        $this->actingAsAdmin();
        NewsletterBasedOnPhone::create(['phone' => '+15005550001']);
        NewsletterBasedOnPhone::create(['phone' => '+15005550002']);
        NewsletterBasedOnPhone::create(['phone' => '+15005550003', 'opted_out' => true]);

        $this->send($this->config(), ['limit' => 5])->assertStatus(202)->assertExactJson([
            'ok' => true,
            'recipients' => 2,
            'filters' => 'all numbers, newest 5',
            'message' => 'Queued an SMS to 2 number(s) (all numbers, newest 5).',
        ]);
        Bus::assertDispatched(SendBulkSmsJob::class);
        $this->assertFalse(Cache::lock(SendBulkSmsJob::runLockKey(), 900)->get());
    }
}
