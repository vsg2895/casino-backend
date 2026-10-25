<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailgunReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

class MailgunReceiverBulkTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private function receiver(string $email, array $attrs = []): MailgunReceiver
    {
        $receiver = MailgunReceiver::create(['email' => $email, 'source' => MailgunReceiver::SOURCE_MANUAL]);
        $receiver->forceFill($attrs)->save();

        return $receiver;
    }

    public function test_bulk_returns_422_for_an_action_other_than_delete(): void
    {
        $this->actingAsAdmin();
        $receiver = $this->receiver('a@example.test');

        $this->postJson('/api/v1/admin/mailgun-receivers/bulk', ['action' => 'deactivate', 'ids' => [$receiver->id]])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The selected action is invalid.',
                'errors' => ['action' => ['The selected action is invalid.']],
            ]);

        $this->assertNotSoftDeleted($receiver);
    }

    public function test_bulk_delete_soft_deletes_only_the_given_receivers(): void
    {
        $this->actingAsAdmin();
        $gone = $this->receiver('gone@example.test');
        $kept = $this->receiver('kept@example.test');

        $this->postJson('/api/v1/admin/mailgun-receivers/bulk', ['action' => 'delete', 'ids' => [$gone->id, 999]])
            ->assertOk()
            ->assertExactJson(['affected' => 1]);

        $this->assertSoftDeleted($gone);
        $this->assertNotSoftDeleted($kept);
    }

    public function test_reset_sends_returns_422_when_clear_errors_is_not_boolean(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/mailgun-receivers/reset-sends', ['clear_errors' => 'maybe'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The clear errors field must be true or false.',
                'errors' => ['clear_errors' => ['The clear errors field must be true or false.']],
            ]);
    }

    public function test_reset_sends_clears_send_state_but_keeps_errors_by_default(): void
    {
        $this->actingAsAdmin();
        $receiver = $this->receiver('a@example.test', ['last_sent_at' => now(), 'sent_count' => 3, 'last_error' => 'Bounced']);

        $this->postJson('/api/v1/admin/mailgun-receivers/reset-sends')
            ->assertOk()
            ->assertExactJson(['affected' => 1]);

        $receiver->refresh();
        $this->assertNull($receiver->last_sent_at);
        $this->assertSame(0, (int) $receiver->sent_count);
        $this->assertSame('Bounced', $receiver->last_error);
    }

    public function test_reset_sends_also_clears_errors_when_asked(): void
    {
        $this->actingAsAdmin();
        $receiver = $this->receiver('a@example.test', ['last_sent_at' => now(), 'sent_count' => 3, 'last_error' => 'Bounced']);

        $this->postJson('/api/v1/admin/mailgun-receivers/reset-sends', ['clear_errors' => true])->assertOk();

        $this->assertNull($receiver->fresh()->last_error);
    }
}
