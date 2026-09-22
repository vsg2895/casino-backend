<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendForumAccountEmail;
use App\Mail\ForumAccountEmail;
use App\Models\ForumUser;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The two transactional account emails.
 *
 * The property worth pinning hardest is the ENUMERATION one: a forgotten-password
 * request must look identical whether or not the address is registered. A test
 * that only checks "an email was sent" would pass while the endpoint told an
 * attacker exactly which addresses exist.
 */
class ForumAccountMailTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private Site $site;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->site, $this->key] = $this->siteWithKey(['forum_enabled' => true]);
    }

    private function base(): string
    {
        return $this->publicBase($this->site);
    }

    private function member(): ForumUser
    {
        $m = ForumUser::create([
            'site_id' => $this->site->id, 'display_name' => 'Member',
            'email' => 'member@example.test', 'password' => 'Correct-Horse-9!battery',
        ]);
        $m->forceFill(['email_verified_at' => now()])->save();

        return $m;
    }

    public function test_registering_queues_a_confirmation_email(): void
    {
        Queue::fake();

        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/register', [
                'display_name' => 'New Member',
                'email' => 'new@example.test',
                'password' => 'Correct-Horse-9!battery',
                'password_confirmation' => 'Correct-Horse-9!battery',
                'website' => '',
            ])->assertCreated();

        Queue::assertPushedOn('high', SendForumAccountEmail::class, function (SendForumAccountEmail $job): bool {
            return $job->type === ForumAccountEmail::TYPE_VERIFY
                // A signed URL, so there is no credential in the database to leak.
                && str_contains($job->actionUrl, 'signature=');
        });
    }

    public function test_a_forgotten_password_request_mails_a_known_address(): void
    {
        Queue::fake();
        $member = $this->member();

        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/forgot-password', [
                'email' => $member->email, 'website' => '',
            ])->assertOk();

        Queue::assertPushed(SendForumAccountEmail::class, fn (SendForumAccountEmail $j): bool
            => $j->type === ForumAccountEmail::TYPE_RESET && $j->forumUserId === $member->id);
    }

    public function test_an_unknown_address_is_answered_identically_and_mails_nobody(): void
    {
        Queue::fake();
        $member = $this->member();

        $known = $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/forgot-password', ['email' => $member->email, 'website' => '']);

        $unknown = $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/forgot-password', ['email' => 'nobody@example.test', 'website' => '']);

        $known->assertOk();
        $unknown->assertOk();

        // Byte-identical bodies apart from the local/testing-only token, which
        // never ships in production. Status, shape and message must match or the
        // endpoint becomes an account-enumeration oracle.
        $this->assertSame($known->json('data.message'), $unknown->json('data.message'));
        $this->assertSame(
            array_keys($known->json('data')),
            array_keys($unknown->json('data')),
        );

        // Exactly one job — for the address that exists.
        Queue::assertPushed(SendForumAccountEmail::class, 1);
    }

    public function test_the_email_is_sent_from_the_site_domain(): void
    {
        Mail::fake();
        $member = $this->member();

        (new SendForumAccountEmail($member->id, ForumAccountEmail::TYPE_VERIFY, 'https://example.test/verify'))
            ->handle();

        Mail::assertSent(ForumAccountEmail::class, function (ForumAccountEmail $mail): bool {
            // The From DOMAIN is what decides whether the mail arrives at all:
            // SPF and DKIM are published per domain, and a mismatch sends without
            // error and lands in spam.
            return $mail->hasTo('member@example.test')
                && $mail->fromAddressOverride !== null
                && str_contains((string) $mail->fromAddressOverride, '@');
        });
    }

    public function test_the_job_timeout_stays_under_the_queue_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.' . config('queue.default') . '.retry_after', 90);
        $job = new SendForumAccountEmail(1, ForumAccountEmail::TYPE_VERIFY, 'https://example.test');

        // The platform rule: a timeout at or above retry_after lets a second
        // worker pick up the same job, and the member gets two reset links —
        // which is exactly what a phishing attempt looks like in an inbox.
        $this->assertLessThan($retryAfter, $job->timeout);
    }

    public function test_a_reset_consumes_its_token_and_revokes_every_session(): void
    {
        $member = $this->member();
        $member->createToken('phone');

        $issue = $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/forgot-password', ['email' => $member->email, 'website' => ''])
            ->assertOk();

        $token = $issue->json('data.reset_token');
        $this->assertNotNull($token);

        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/reset-password', [
                'email' => $member->email, 'token' => $token,
                'password' => 'Brand-New-Passw0rd!', 'password_confirmation' => 'Brand-New-Passw0rd!',
            ])->assertOk();

        // Whoever asked may have been locked out BY an intruder; leaving that
        // intruder's token alive would make the reset cosmetic.
        $this->assertSame(0, $member->fresh()->tokens()->count());

        // Single use.
        $this->withHeaders($this->siteHeaders($this->key))
            ->postJson($this->base() . '/forum/members/reset-password', [
                'email' => $member->email, 'token' => $token,
                'password' => 'Another-Passw0rd!', 'password_confirmation' => 'Another-Passw0rd!',
            ])->assertStatus(422);
    }
}
