<?php

declare(strict_types=1);

namespace Tests\Feature\Perfume;

use App\Models\User;
use App\Perfume\Jobs\SendPerfumeWelcomeEmail;
use App\Perfume\Mail\PerfumeWelcomeEmail;
use App\Perfume\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerfumeSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private const array HEADERS = ['X-Perfume-Key' => 'k'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['perfume.api_key' => 'k', 'cache.default' => 'array']);
        Queue::fake();
    }

    public function test_the_welcome_email_goes_through_the_env_sendgrid_key(): void
    {
        Queue::fake();
        config(['mail.mailers.sendgrid.key' => 'SG.test-key']);

        $this->postJson('/api/perfume/public/subscribe', ['email' => 'ani@example.com'], self::HEADERS)
            ->assertStatus(201);

        Queue::assertPushed(SendPerfumeWelcomeEmail::class);
        // The transport is SendGrid's Web API, keyed from .env — the same one
        // the casino sites' public subscription mail uses, and deliberately
        // not the default mailer.
        $this->assertSame('sendgrid', SendPerfumeWelcomeEmail::MAILER);
    }

    public function test_the_welcome_email_is_skipped_when_no_sendgrid_key_is_configured(): void
    {
        Mail::fake();
        config(['mail.mailers.sendgrid.key' => '']);

        $subscriber = Subscriber::create([
            'email' => 'ani@example.com', 'active' => true, 'token' => Subscriber::generateToken(),
        ]);

        (new SendPerfumeWelcomeEmail($subscriber->id))->handle();

        // Nothing sent, and nothing pretends it was.
        Mail::assertNothingSent();
        $this->assertNull($subscriber->refresh()->last_sent_at);
    }

    public function test_the_welcome_email_carries_the_shop_and_an_opt_out(): void
    {
        Mail::fake();
        config(['mail.mailers.sendgrid.key' => 'SG.test-key', 'perfume.site_url' => 'https://bigglowday.com']);

        $subscriber = Subscriber::create([
            'email' => 'ani@example.com', 'name' => 'Ani', 'active' => true, 'token' => Subscriber::generateToken(),
        ]);

        (new SendPerfumeWelcomeEmail($subscriber->id))->handle();

        Mail::assertSent(PerfumeWelcomeEmail::class, function (PerfumeWelcomeEmail $mail) use ($subscriber): bool {
            $rendered = $mail->render();

            return $mail->subscriber->is($subscriber)
                && str_contains($rendered, 'Big Glow Day')
                && str_contains($rendered, 'https://bigglowday.com/unsubscribe/' . $subscriber->token);
        });
    }

    public function test_subscribing_stores_the_address_and_queues_the_welcome_email(): void
    {
        $this->postJson('/api/perfume/public/subscribe', ['email' => 'Ani@Example.com', 'name' => 'Ani'], self::HEADERS)
            ->assertStatus(201)
            ->assertJsonPath('data.subscribed', true);

        $subscriber = Subscriber::firstOrFail();

        // Lower-cased, so the same person cannot join twice by capitalising.
        $this->assertSame('ani@example.com', $subscriber->email);
        $this->assertTrue($subscriber->active);
        $this->assertNotEmpty($subscriber->token);
        Queue::assertPushed(SendPerfumeWelcomeEmail::class);
    }

    public function test_subscribing_twice_is_not_an_error_and_does_not_mail_again(): void
    {
        $this->postJson('/api/perfume/public/subscribe', ['email' => 'ani@example.com'], self::HEADERS)->assertStatus(201);
        Queue::assertPushed(SendPerfumeWelcomeEmail::class, 1);

        $this->postJson('/api/perfume/public/subscribe', ['email' => 'ani@example.com'], self::HEADERS)->assertStatus(201);

        $this->assertSame(1, Subscriber::count());
        Queue::assertPushed(SendPerfumeWelcomeEmail::class, 1);
    }

    public function test_someone_who_opted_out_and_comes_back_is_reactivated_and_greeted(): void
    {
        $subscriber = Subscriber::create([
            'email' => 'ani@example.com', 'active' => false, 'token' => Subscriber::generateToken(),
            'unsubscribed_at' => now(),
        ]);

        $this->postJson('/api/perfume/public/subscribe', ['email' => 'ani@example.com'], self::HEADERS)->assertStatus(201);

        $subscriber->refresh();
        $this->assertTrue($subscriber->active);
        $this->assertNull($subscriber->unsubscribed_at);
        Queue::assertPushed(SendPerfumeWelcomeEmail::class);
    }

    public function test_the_token_opts_someone_out_and_an_unknown_token_says_the_same(): void
    {
        $subscriber = Subscriber::create(['email' => 'ani@example.com', 'active' => true, 'token' => Subscriber::generateToken()]);

        $this->postJson('/api/perfume/public/unsubscribe', ['token' => $subscriber->token], self::HEADERS)
            ->assertOk()->assertJsonPath('data.unsubscribed', true);

        $this->assertFalse($subscriber->refresh()->active);
        $this->assertNotNull($subscriber->unsubscribed_at);

        // The same answer for a token that never existed: this must not be a
        // way to find out whether an address is on the list.
        $this->postJson('/api/perfume/public/unsubscribe', ['token' => 'nonsense'], self::HEADERS)
            ->assertOk()->assertJsonPath('data.unsubscribed', true);
    }

    public function test_a_bad_address_is_refused(): void
    {
        $this->postJson('/api/perfume/public/subscribe', ['email' => 'not-an-email'], self::HEADERS)
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_the_admin_manages_the_list_and_never_sees_a_token(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $id = $this->postJson('/api/perfume/admin/subscribers', ['email' => 'ani@example.com', 'name' => 'Ani'])
            ->assertStatus(201)
            ->assertJsonMissingPath('data.token')
            ->json('data.id');

        $this->assertNotEmpty(Subscriber::find($id)->token);

        // Switching someone off by hand stamps the opt-out like the link does.
        $this->patchJson("/api/perfume/admin/subscribers/{$id}", ['active' => false])
            ->assertOk()->assertJsonPath('data.active', false);
        $this->assertNotNull(Subscriber::find($id)->unsubscribed_at);

        $this->postJson('/api/perfume/admin/subscribers', ['email' => 'ani@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->deleteJson("/api/perfume/admin/subscribers/{$id}")->assertStatus(204);
        $this->assertSame(0, Subscriber::count());
    }
}
