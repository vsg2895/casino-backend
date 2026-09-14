<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Notifications\PasswordChanged;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Sign in, ending every other session for this account.
     *
     * Signing in REVOKES every existing token for the user before minting the
     * new one, so an admin is signed in on exactly one device at a time. That is
     * a deliberate policy for this panel rather than a convenience: it holds the
     * keys to every site's content and to credentials that send mail as the
     * business, and a forgotten session on a shared or lost machine is the way
     * that access leaks. It also gives an operator who suspects a compromise a
     * remedy they can reach without an admin screen — sign in again.
     *
     * The count of what was revoked is returned so the panel can say so out
     * loud; a silent sign-out elsewhere looks like a bug to whoever it happens to.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        $revoked = $user->tokens()->count();
        $user->tokens()->delete();

        $expiresAt = $this->tokenExpiry();

        // Passed explicitly rather than left to config('sanctum.expiration'):
        // the config form is enforced at authentication time but leaves
        // `expires_at` NULL in the database, so the panel would have no way to
        // know when its session ends. An explicit value both enforces AND is
        // readable, which is what lets the client sign out on its own schedule
        // instead of discovering the expiry through a failed request.
        $token = $user->createToken('admin-panel', ['*'], $expiresAt)->plainTextToken;

        return response()->json([
            'token'      => $token,
            'expires_at' => $expiresAt?->toISOString(),
            // Other devices signed out by this login. 0 on a clean first sign-in.
            'revoked_sessions' => $revoked,
            'user'       => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out']);
    }

    /**
     * End every OTHER session, keeping the one making this request.
     *
     * Login already does this implicitly. This is the explicit control for the
     * case where an admin is already signed in, suspects a session elsewhere,
     * and does not want to sign themselves out to deal with it.
     */
    public function logoutOtherDevices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = $request->user()->currentAccessToken();

        $revoked = $user->tokens()->where('id', '!=', $current->id)->delete();

        return response()->json([
            'revoked_sessions' => $revoked,
            'message'          => $revoked === 0
                ? 'No other sessions were active.'
                : "Signed out {$revoked} other session(s).",
        ]);
    }

    /**
     * Change your own password while signed in.
     *
     * The current password is required and is verified in ChangePasswordRequest
     * — read the note there about why Laravel's `current_password` rule is not
     * usable behind `auth:sanctum`.
     *
     * EVERY token dies here, including the one making this request, and a fresh
     * one is minted and returned. Keeping the caller's token alive would have
     * been friendlier to write and is what most panels do, but a password change
     * is a credential change, and the standing advice after one is to renew the
     * session identifier: if the reason for the change is that a token leaked,
     * anything that survives the change defeats it. Because the replacement is
     * returned in the same response, the operator is not signed out — the panel
     * swaps the token and the screen carries on.
     *
     * The write and the revocation share a transaction. Split, a failure between
     * them leaves the new password live with old sessions still attached, which
     * is precisely the state this is meant to prevent.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $expiresAt = $this->tokenExpiry();
        $newPassword = $request->string('password')->value();

        $result = DB::transaction(function () use ($user, $newPassword, $expiresAt): array {
            $user->forceFill([
                // forceFill bypasses $fillable but NOT the casts, so the
                // 'hashed' cast would hash this anyway. Hash::make is written
                // out because a security-critical line should not depend on a
                // cast declared in another file staying where it is.
                'password' => Hash::make($newPassword),
                // Any "remember me" cookie issued against the old password is
                // dead from here, the same as in the reset path above.
                'remember_token' => Str::random(60),
            ])->save();

            $revoked = $user->tokens()->count();
            $user->tokens()->delete();

            return [
                'revoked' => $revoked,
                'token'   => $user->createToken('admin-panel', ['*'], $expiresAt)->plainTextToken,
            ];
        });

        $changedAt = now();

        /*
         * Told, not asked. The notification goes to the address on the account,
         * which is a channel a session-only attacker does not hold — so if the
         * change was not the owner's, this is how they find out.
         *
         * Wrapped because the password is already changed and committed: a mail
         * transport that is down must not turn a successful change into a 500
         * that invites the operator to "try again" with a password that is no
         * longer current.
         */
        try {
            $user->notify(new PasswordChanged($changedAt));
        } catch (\Throwable $e) {
            Log::warning('Password changed, but the notification mail could not be queued.', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Deliberately no password material, not even its length.
        Log::info('Admin password changed.', [
            'user_id'          => $user->id,
            'revoked_sessions' => $result['revoked'],
        ]);

        return response()->json([
            'message' => 'Password changed. All other sessions were signed out.',
            // The caller's own token was revoked with the rest; this replaces it
            // so the panel stays signed in. The client MUST store it.
            'token'            => $result['token'],
            'expires_at'       => $expiresAt?->toISOString(),
            // Sessions ended besides this one.
            'revoked_sessions' => max(0, $result['revoked'] - 1),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            ...$this->userPayload($user),
            // Lets a panel restored from localStorage learn how long it has left
            // without waiting for a request to fail.
            // Null-safe on the TOKEN as well as on the date. A real request through
            // `auth:sanctum` always has a token, but `actingAs($user, 'sanctum')`
            // authenticates without minting one, so this line was a 500 for every
            // caller that did not come in over HTTP with a bearer token. Answering
            // `expires_at: null` degrades honestly — the client simply falls back
            // to learning about expiry from its next 401.
            'expires_at' => $request->user()->currentAccessToken()?->expires_at?->toISOString(),
        ]);
    }

    /**
     * Email a password reset link.
     *
     * Always answers the same way, whether or not the address belongs to an
     * account. Confirming which emails are registered turns this endpoint into
     * an account-enumeration oracle for the admin panel, and the person who
     * legitimately forgot their password learns nothing extra from a distinct
     * "no such user" message.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that address belongs to an account, a reset link is on its way.',
        ]);
    }

    /**
     * Complete a reset, then end every session for the account.
     *
     * Revoking the tokens is the point of resetting a password after a
     * compromise. Leaving them alive would let whoever prompted the reset keep
     * using the session they already hold, which is the failure the reset was
     * meant to close.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            static function (User $user, string $password): void {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // The broker's own wording covers the cases that matter — an expired
            // or already-used token, and the throttle — and says them better
            // than a generic failure would.
            return response()->json([
                'message' => __($status),
                'errors'  => ['email' => [__($status)]],
            ], 422);
        }

        return response()->json([
            'message' => 'Password updated. Sign in with your new password.',
        ]);
    }

    /**
     * When a session minted now should end, or null when expiry is disabled.
     *
     * Reads `sanctum.expiration` so the lifetime has ONE home: the same value
     * Sanctum enforces on every authenticated request is the one written onto
     * the token and handed to the client.
     */
    private function tokenExpiry(): ?Carbon
    {
        $minutes = (int) config('sanctum.expiration');

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
        ];
    }
}
