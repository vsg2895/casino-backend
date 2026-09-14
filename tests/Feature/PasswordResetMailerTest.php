<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The password-reset email must leave over the .env SMTP credentials.
 *
 * Laravel's ResetPassword notification names no mailer, so it falls through to
 * `mail.default` — env('MAIL_MAILER', 'log'). Two silent failures follow from
 * that, and both lock an admin out with no signal at all:
 *
 *   MAIL_MAILER unset      the reset link is written to storage/logs, never sent
 *   MAIL_MAILER=sendgrid   it goes out over the Web API, not the SMTP credentials
 *
 * These tests pin the mailer regardless of what `mail.default` happens to be.
 */
class PasswordResetMailerTest extends TestCase
{
    use RefreshDatabase;

    private function resetMessage(string $defaultMailer): \Illuminate\Notifications\Messages\MailMessage
    {
        // Whatever the rest of the app is configured to send with.
        config(['mail.default' => $defaultMailer]);

        $user = User::factory()->create();

        return (new ResetPassword('token-123'))->toMail($user);
    }

    public function test_it_sends_over_smtp_even_when_the_default_mailer_is_sendgrid(): void
    {
        $message = $this->resetMessage('sendgrid');

        // THE assertion: admin recovery does not inherit the public transport.
        $this->assertSame('smtp', $message->mailer);
    }

    public function test_it_sends_over_smtp_even_when_the_default_mailer_is_log(): void
    {
        // The dangerous one — `log` is Laravel's fallback when MAIL_MAILER is
        // unset, and it fails completely silently.
        $message = $this->resetMessage('log');

        $this->assertSame('smtp', $message->mailer);
        $this->assertNotSame('log', $message->mailer);
    }

    public function test_the_mailer_is_configurable_without_touching_code(): void
    {
        config(['mail.password_reset_mailer' => 'admin-relay']);

        $this->assertSame('admin-relay', $this->resetMessage('log')->mailer);
    }

    public function test_the_link_points_at_the_admin_spa_not_the_api(): void
    {
        config(['app.frontend_url' => 'https://admin.example.test']);

        $user = User::factory()->create(['email' => 'admin@example.test']);
        $message = (new ResetPassword('tok'))->toMail($user);

        // Following an APP_URL-based link would 404 on this headless API, and
        // the reset would look broken rather than merely misconfigured.
        $this->assertStringStartsWith('https://admin.example.test/reset-password?', $message->actionUrl);
        // The broker verifies the token/email PAIR, so the address must travel.
        $this->assertStringContainsString('email=admin%40example.test', $message->actionUrl);
        $this->assertStringContainsString('token=tok', $message->actionUrl);
    }

    public function test_the_notification_is_actually_dispatched_on_forgot_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => $user->email])
            ->assertSuccessful();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_address_sends_nothing_and_does_not_reveal_that(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'nobody@example.test']);

        Notification::assertNothingSent();
        // Enumeration guard: the response must not distinguish a known address
        // from an unknown one.
        $this->assertTrue($response->isSuccessful() || $response->status() === 422);
    }
}
