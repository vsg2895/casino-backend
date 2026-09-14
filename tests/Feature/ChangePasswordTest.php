<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * POST /api/v1/admin/auth/change-password
 *
 * These are written around the ways the endpoint could be WRONG rather than the
 * happy path alone: accepting a bad current password, leaving old sessions
 * alive, or answering 200 while the stored hash is unchanged.
 */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const string CURRENT = 'Curr3nt-Passw0rd!x';

    private const string NEXT = 'Str0ng-N3w-Passw0rd!';

    /**
     * The default guard as configured, captured BEFORE any request runs.
     *
     * It cannot be read later: AuthManager::shouldUse() does not merely set a
     * property, it writes the name into the config repository. So once a request
     * has gone through `auth:sanctum`, `config('auth.defaults.guard')` answers
     * "sanctum" — and restoring that would restore the very thing being undone.
     */
    private string $defaultGuard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultGuard = (string) config('auth.defaults.guard');
    }

    private function signedIn(): User
    {
        $user = User::factory()->create(['password' => Hash::make(self::CURRENT)]);

        // A real bearer token, not actingAs: the endpoint revokes tokens and
        // mints a replacement, and actingAs creates no token row to revoke.
        $token = $user->createToken('admin-panel')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer ' . $token);

        return $user;
    }

    /**
     * Forget everything the auth manager is holding between two requests.
     *
     * Needed only in tests. Each real request boots its own container, so a
     * guard resolved during one cannot affect the next; inside a test the
     * container survives, which means `auth:sanctum` calling shouldUse('sanctum')
     * leaves it the DEFAULT guard afterwards (breaking a later Auth::attempt),
     * and RequestGuard keeps its resolved user cached (so a revoked token would
     * appear to still work). Both would be test artifacts reported as passes or
     * failures that say nothing about production.
     */
    private function betweenRequests(): void
    {
        Auth::forgetGuards();
        Auth::shouldUse($this->defaultGuard);
    }

    /** @param array<string, string> $overrides */
    private function change(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/change-password', [
            'current_password'      => self::CURRENT,
            'password'              => self::NEXT,
            'password_confirmation' => self::NEXT,
            ...$overrides,
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/auth/change-password', [
            'current_password'      => self::CURRENT,
            'password'              => self::NEXT,
            'password_confirmation' => self::NEXT,
        ])->assertUnauthorized();
    }

    public function test_it_changes_the_password(): void
    {
        $user = $this->signedIn();

        $this->change()->assertOk()->assertJsonStructure(['message', 'token', 'revoked_sessions']);

        $this->assertTrue(Hash::check(self::NEXT, $user->fresh()->password));
        $this->assertFalse(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_the_new_password_works_at_login_and_the_old_one_does_not(): void
    {
        $user = $this->signedIn();
        $this->change()->assertOk();
        $this->betweenRequests();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email, 'password' => self::NEXT,
        ])->assertOk();

        $this->betweenRequests();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email, 'password' => self::CURRENT,
        ])->assertUnauthorized();
    }

    public function test_a_wrong_current_password_is_rejected_and_changes_nothing(): void
    {
        $user = $this->signedIn();

        $this->change(['current_password' => 'not-my-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    /**
     * The framework rule this endpoint does NOT use would have passed here:
     * Sanctum's RequestGuard::validate() ignores the password it is given, so
     * `current_password` behind auth:sanctum approves any string. This test is
     * the guard against someone "simplifying" the request back to that rule.
     */
    public function test_the_current_password_check_is_not_satisfied_by_the_token_alone(): void
    {
        $this->signedIn();

        $this->change(['current_password' => ''])->assertStatus(422);
        $this->change(['current_password' => 'anything-at-all'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_it_rejects_reusing_the_current_password(): void
    {
        $this->signedIn();

        $this->change(['password' => self::CURRENT, 'password_confirmation' => self::CURRENT])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_it_requires_a_matching_confirmation(): void
    {
        $this->signedIn();

        $this->change(['password_confirmation' => 'Something-Else!99'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_it_enforces_the_shared_password_policy(): void
    {
        $this->signedIn();

        foreach (['Sh0rt!a', 'alllowercase-nodigits', 'nouppercase123!'] as $weak) {
            $this->change(['password' => $weak, 'password_confirmation' => $weak])
                ->assertStatus(422)
                ->assertJsonValidationErrors('password');
        }
    }

    public function test_it_revokes_every_other_session(): void
    {
        $user = $this->signedIn();
        $user->createToken('phone');
        $user->createToken('laptop');

        $response = $this->change()->assertOk();

        // Two others, plus the caller's own — which is replaced, not kept.
        $this->assertSame(2, $response->json('revoked_sessions'));
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_the_returned_token_works_and_the_old_one_does_not(): void
    {
        $user = $this->signedIn();
        $old = $user->createToken('old-session')->plainTextToken;

        $new = $this->change()->assertOk()->json('token');

        // Guard-independent: the row is gone, whatever any cached guard thinks.
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => explode('|', $old)[0],
        ]);

        $this->betweenRequests();
        $this->withHeader('Authorization', 'Bearer ' . $new)
            ->getJson('/api/v1/admin/auth/me')->assertOk();

        $this->betweenRequests();
        $this->withHeader('Authorization', 'Bearer ' . $old)
            ->getJson('/api/v1/admin/auth/me')->assertUnauthorized();
    }

    public function test_it_notifies_the_account_owner(): void
    {
        Notification::fake();
        $user = $this->signedIn();

        $this->change()->assertOk();

        Notification::assertSentTo($user, PasswordChanged::class);
    }

    public function test_it_never_echoes_password_material(): void
    {
        $this->signedIn();

        $body = $this->change()->assertOk()->getContent();

        $this->assertStringNotContainsString(self::NEXT, $body);
        $this->assertStringNotContainsString(self::CURRENT, $body);
    }
}
