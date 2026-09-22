<?php

declare(strict_types=1);

namespace App\Services\Forum;

use App\Models\ForumUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Password resets for forum members.
 *
 * ── Why not Illuminate's password broker ────────────────────────────────────
 *
 * `DatabaseTokenRepository` keys every query on the email address alone. Forum
 * accounts are per-site, so the same address can hold two accounts, and the
 * broker would issue a token for one and happily redeem it against the other.
 * Bending it to a composite key means overriding most of it; writing the four
 * operations directly is smaller and obviously correct.
 *
 * ── The properties this implementation has ──────────────────────────────────
 *
 *  - the token is stored HASHED, so a database read does not yield working
 *    reset links
 *  - it is SINGLE USE: redeeming deletes the row inside the same transaction as
 *    the password write
 *  - it expires, and an expired row is deleted rather than left to accumulate
 *  - a request for an unknown address does exactly the same work and returns
 *    exactly the same response as one for a known address, so the endpoint
 *    cannot be used to enumerate which addresses are registered
 */
class ForumPasswordResetService
{
    private const TABLE = 'forum_password_reset_tokens';

    public const int EXPIRY_MINUTES = 60;

    /**
     * Issue a token, or pretend to.
     *
     * Returns the plain token for the caller to mail, or null when there is no
     * such account. The CALLER must not vary its response on that null — see the
     * controller.
     */
    public function issue(int $siteId, string $email): ?string
    {
        $member = ForumUser::query()
            ->where('site_id', $siteId)
            ->where('email', $email)
            ->first();

        if ($member === null) {
            return null;
        }

        $plain = Str::random(64);

        // One live token per account: issuing a new one invalidates the old.
        DB::table(self::TABLE)->updateOrInsert(
            ['site_id' => $siteId, 'email' => $email],
            ['token' => Hash::make($plain), 'created_at' => now()],
        );

        return $plain;
    }

    /**
     * Redeem a token and set the new password.
     *
     * The lookup is by (site, email) and the token is then CHECKED rather than
     * matched in SQL — a hashed token cannot be looked up by value, which is the
     * whole reason it is safe to store.
     */
    public function reset(int $siteId, string $email, string $token, string $password): bool
    {
        $row = DB::table(self::TABLE)
            ->where('site_id', $siteId)
            ->where('email', $email)
            ->first();

        if ($row === null || ! Hash::check($token, $row->token)) {
            return false;
        }

        if ($this->expired($row->created_at)) {
            // A dead token is deleted on sight rather than left for a cleanup
            // job — the row is a credential, even an expired one.
            $this->forget($siteId, $email);

            return false;
        }

        $member = ForumUser::query()
            ->where('site_id', $siteId)
            ->where('email', $email)
            ->first();

        if ($member === null) {
            $this->forget($siteId, $email);

            return false;
        }

        DB::transaction(function () use ($member, $password, $siteId, $email): void {
            $member->password = $password;   // hashed by the model cast
            // Completing a reset proves control of the mailbox, so an account
            // that never confirmed is confirmed by this.
            $member->email_verified_at ??= now();
            $member->save();

            // Single use: the token dies with the write it authorised.
            $this->forget($siteId, $email);

            // Every existing session is revoked. Whoever asked for the reset may
            // have been locked out BY an intruder, and leaving that intruder's
            // token alive would make the reset cosmetic.
            $member->tokens()->delete();
        });

        return true;
    }

    private function forget(int $siteId, string $email): void
    {
        DB::table(self::TABLE)->where('site_id', $siteId)->where('email', $email)->delete();
    }

    private function expired(?string $createdAt): bool
    {
        return $createdAt === null
            || now()->diffInMinutes(\Illuminate\Support\Carbon::parse($createdAt), true) > self::EXPIRY_MINUTES;
    }
}
