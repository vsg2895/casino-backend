<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            ...$this->userPayload($user),
            // Lets a panel restored from localStorage learn how long it has left
            // without waiting for a request to fail.
            'expires_at' => $request->user()->currentAccessToken()->expires_at?->toISOString(),
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
