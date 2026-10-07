<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ForumUser;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * Sign in with Google, for forum members.
 *
 * The browser gets an ID token from Google and posts it here; this endpoint
 * decides whether to believe it. Google's `tokeninfo` is faked throughout —
 * these tests are about what we do with the claims, not about Google.
 *
 * The check that carries the most weight is `aud`: without it the endpoint
 * accepts a token minted for ANY Google application, and anyone with a client
 * id of their own could sign in as any member. It gets its own test.
 */
class ForumGoogleSignInTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const string CLIENT_ID = '1234567890-test.apps.googleusercontent.com';

    private Site $site;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => self::CLIENT_ID]);

        [$this->site, $this->key] = $this->siteWithKey(['forum_enabled' => true]);
    }

    /** @param array<string, mixed> $claims */
    private function fakeGoogle(array $claims = [], int $status = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud'            => self::CLIENT_ID,
                'iss'            => 'https://accounts.google.com',
                'sub'            => '110000000000000000001',
                'email'          => 'kate@example.com',
                // Google sends this as a STRING, which is exactly the trap the
                // service has to handle.
                'email_verified' => 'true',
                'name'           => 'Kate Example',
                ...$claims,
            ], $status),
        ]);
    }

    private function signIn(string $token = 'any.id.token'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(
            $this->publicBase($this->site) . '/forum/members/google',
            ['id_token' => $token],
            $this->siteHeaders($this->key),
        );
    }

    public function test_a_first_sign_in_creates_a_verified_member(): void
    {
        $this->fakeGoogle();

        $res = $this->signIn()->assertOk();

        $this->assertNotEmpty($res->json('data.token'));

        $member = ForumUser::where('site_id', $this->site->id)->sole();
        $this->assertSame('kate@example.com', $member->email);
        $this->assertSame('Kate Example', $member->display_name);
        $this->assertSame('110000000000000000001', $member->google_id);
        // Google proved the address, which is the only thing the verification
        // email exists to prove.
        $this->assertNotNull($member->email_verified_at);
    }

    public function test_signing_in_again_reuses_the_same_account(): void
    {
        $this->fakeGoogle();

        $this->signIn()->assertOk();
        $this->signIn()->assertOk();

        $this->assertSame(1, ForumUser::where('site_id', $this->site->id)->count());
    }

    public function test_an_existing_password_account_is_linked_rather_than_duplicated(): void
    {
        $existing = ForumUser::create([
            'site_id'      => $this->site->id,
            'display_name' => 'Kate',
            'email'        => 'kate@example.com',
            'password'     => 'secret-password',
        ]);

        $this->fakeGoogle();
        $this->signIn()->assertOk();

        $this->assertSame(1, ForumUser::where('site_id', $this->site->id)->count());
        $this->assertSame('110000000000000000001', $existing->fresh()->google_id);
        // Their own display name survives — the Google profile does not rename
        // an account that already existed.
        $this->assertSame('Kate', $existing->fresh()->display_name);
    }

    public function test_a_token_minted_for_another_application_is_refused(): void
    {
        // The whole security of this endpoint. A valid, unexpired, correctly
        // signed Google token for SOMEONE ELSE'S app must not sign anybody in.
        $this->fakeGoogle(['aud' => 'someone-elses-app.apps.googleusercontent.com']);

        $this->signIn()->assertStatus(422);
        $this->assertSame(0, ForumUser::count());
    }

    public function test_an_unverified_google_address_is_refused(): void
    {
        $this->fakeGoogle(['email_verified' => 'false']);

        $this->signIn()->assertStatus(422);
        $this->assertSame(0, ForumUser::count());
    }

    public function test_an_unexpected_issuer_is_refused(): void
    {
        $this->fakeGoogle(['iss' => 'https://accounts.evil.example']);

        $this->signIn()->assertStatus(422);
    }

    public function test_a_token_google_rejects_is_refused(): void
    {
        $this->fakeGoogle([], 400);

        $this->signIn()->assertStatus(422);
        $this->assertSame(0, ForumUser::count());
    }

    public function test_a_banned_member_cannot_sign_in_with_google(): void
    {
        $member = ForumUser::create([
            'site_id'      => $this->site->id,
            'display_name' => 'Kate',
            'email'        => 'kate@example.com',
            'password'     => 'secret-password',
        ]);
        $member->forceFill(['status' => ForumUser::STATUS_BANNED])->save();

        $this->fakeGoogle();

        $this->signIn()->assertStatus(403);
    }

    public function test_the_endpoint_is_off_when_no_client_id_is_configured(): void
    {
        config(['services.google.client_id' => null]);
        Http::fake();

        $this->signIn()->assertStatus(503);
        // Never even asked Google: with no client id there is no audience to
        // check the token against, so there is nothing to verify it with.
        Http::assertNothingSent();
    }

    public function test_an_account_is_scoped_to_one_site(): void
    {
        [$other, $otherKey] = $this->siteWithKey(['forum_enabled' => true]);

        $this->fakeGoogle();
        $this->signIn()->assertOk();

        $this->postJson(
            $this->publicBase($other) . '/forum/members/google',
            ['id_token' => 'any.id.token'],
            $this->siteHeaders($otherKey),
        )->assertOk();

        // The same Google identity, two domains, two accounts — the same rule
        // the email/password registration follows.
        $this->assertSame(1, ForumUser::where('site_id', $this->site->id)->count());
        $this->assertSame(1, ForumUser::where('site_id', $other->id)->count());
    }

    public function test_a_profile_without_a_name_falls_back_to_the_address_local_part(): void
    {
        $this->fakeGoogle(['name' => '']);

        $this->signIn()->assertOk();

        // Never the whole address: that would put somebody's email on every
        // post they write.
        $this->assertSame('kate', ForumUser::sole()->display_name);
    }
}
