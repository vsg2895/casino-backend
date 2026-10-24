<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\Forum\RegisterForumUserRequest;
use App\Models\ForumUser;
use App\Models\Site;
use App\Services\Auth\GoogleIdentityService;
use App\Services\Forum\ForumAccountService;
use App\Services\Forum\ForumPasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accounts for the community forum.
 *
 * The platform's first visitor auth. Everything here is scoped to the site
 * resolved by VerifySiteAccess — an account is valid on one domain, which is the
 * decision recorded in the forum_users migration.
 *
 * Tokens are issued on the `forum` guard, so a member token can never satisfy an
 * admin route: Sanctum checks the tokenable_type, and the admin routes name the
 * default guard while these name `auth:forum`.
 */
class ForumAuthController extends Controller
{
    public function __construct(
        private readonly ForumAccountService $accounts,
        private readonly ForumPasswordResetService $resets,
    ) {}

    /**
     * Register.
     *
     * Deliberately does NOT sign the member in. The account cannot post until
     * the address is confirmed, so handing back a token would only create a
     * session that is refused by every write endpoint — confusing, and it would
     * make an unconfirmed address look like a working account.
     */
    public function register(RegisterForumUserRequest $request): JsonResponse
    {
        $site = $this->site();

        $link = $this->accounts->register(
            $site,
            $request->safe()->only(['display_name', 'email', 'password']),
            $this->packedIp($request),
            $request->boolean('marketing_consent'),
        );

        return response()->json([
            'data' => [
                'registered' => true,
                'message' => 'Check your email to confirm your address before posting.',
                // Returned ONLY outside production, so the flow stays testable
                // without reading a mailbox. In production the link exists only
                // in the email.
                'verify_url' => app()->environment('local', 'testing') ? $link : null,
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Sign in.
     *
     * One generic message for a bad address and a bad password alike. Naming
     * which half was wrong turns the endpoint into an account-enumeration oracle.
     */
    public function login(Request $request): JsonResponse
    {
        $site = $this->site();

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        // One generic answer for a bad address and a bad password alike, at the
        // same cost — see ForumAccountService::memberForCredentials().
        $member = $this->accounts->memberForCredentials($site, $credentials['email'], $credentials['password']);

        if ($member === null) {
            return response()->json(['message' => 'Those details do not match an account.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($member->status === ForumUser::STATUS_BANNED) {
            return response()->json(['message' => 'This account cannot sign in.'], Response::HTTP_FORBIDDEN);
        }

        $token = $this->accounts->issueToken($member, $site);

        $member->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'data' => [
                'token' => $token,
                'member' => $this->profile($member),
            ],
        ]);
    }

    /**
     * Sign in with Google.
     *
     * The browser obtains an ID token from Google Identity Services and posts it
     * here; nothing in this flow redirects, and no client secret exists. See
     * {@see GoogleIdentityService} for what is checked before any of this runs —
     * in particular that the token was minted for OUR client id.
     *
     * Which account it lands in — linked, matched by address, or new — is
     * {@see ForumAccountService::memberForGoogle()}. A new account is created
     * already verified, because the thing the verification email exists to
     * prove has just been proven by Google.
     */
    public function google(Request $request, GoogleIdentityService $google): JsonResponse
    {
        $site = $this->site();

        if (! $google->configured()) {
            return response()->json(['message' => 'Google sign-in is not available.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $validated = $request->validate([
            // Long: a Google ID token is a JWT with a full profile in it.
            'id_token' => ['required', 'string', 'max:4096'],
        ]);

        $identity = $google->verify($validated['id_token']);

        if ($identity === null) {
            // One message for every failure — a bad audience, an expired token
            // and an unverified address are not distinguished, because naming
            // the failing check only helps whoever is probing.
            return response()->json(['message' => 'Could not sign in with Google.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $member = $this->accounts->memberForGoogle($site, $identity);

        if ($member->status === ForumUser::STATUS_BANNED) {
            return response()->json(['message' => 'This account cannot sign in.'], Response::HTTP_FORBIDDEN);
        }

        $this->accounts->linkGoogle($member, $identity);

        return response()->json([
            'data' => [
                'token' => $this->accounts->issueToken($member, $site),
                'member' => $this->profile($member),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['data' => ['signed_out' => true]]);
    }

    /** The signed-in member — what the front end renders the header from. */
    public function me(Request $request): JsonResponse
    {
        /** @var ForumUser $member */
        $member = $request->user();

        // Cheap presence write. Throttled to once a minute so reading a thread
        // does not write a row per request.
        if ($member->last_seen_at === null || $member->last_seen_at->diffInMinutes(now()) >= 1) {
            $member->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return response()->json(['data' => $this->profile($member)]);
    }

    /**
     * Confirm an address from the emailed link.
     *
     * A SIGNED route rather than a stored token: verifying twice is harmless, so
     * single use buys nothing, and a signature means there is no credential in
     * the database to leak. POST-only, like the platform's other mail-link
     * endpoints — mail clients prefetch GET links, which would auto-confirm
     * addresses nobody clicked.
     */
    public function verify(Request $request, string $site, int $member): JsonResponse
    {
        abort_unless($request->hasValidSignature(), Response::HTTP_FORBIDDEN, 'That link is invalid or has expired.');

        // `$site` is the URL slug, unused — route parameters bind positionally.
        $current = $this->site();

        abort_unless($this->accounts->confirm($current, $member), Response::HTTP_NOT_FOUND);

        return response()->json(['data' => ['verified' => true]]);
    }

    /**
     * Start a password reset.
     *
     * Always the same response, always the same work. Whether the address is
     * registered is not something an unauthenticated caller is entitled to learn.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $site = $this->site();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'website' => ['present', 'max:0'],
        ]);

        // Mails only when there IS an account; the answer is identical either way.
        $token = $this->accounts->sendPasswordReset($site, $data['email']);

        return response()->json([
            'data' => [
                'sent' => true,
                'message' => 'If that address has an account, a reset link is on its way.',
                'reset_token' => app()->environment('local', 'testing') ? $token : null,
            ],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $site = $this->site();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            // Same simple rule as registration — see RegisterForumUserRequest.
            'password' => ['required', 'string', 'min:6', 'max:200'],
        ]);

        $ok = $this->resets->reset((int) $site->id, $data['email'], $data['token'], $data['password']);

        if (! $ok) {
            return response()->json([
                'message' => 'That reset link is invalid or has expired. Request a new one.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => ['reset' => true]]);
    }

    /** @return array<string, mixed> */
    private function profile(ForumUser $member): array
    {
        return [
            'id' => (int) $member->id,
            'display_name' => $member->display_name,
            'slug' => $member->slug,
            'avatar_path' => $member->avatar_path,
            'posts_count' => (int) $member->approved_posts_count,
            'verified' => $member->email_verified_at !== null,
            // The front end uses these to explain WHY a reply is held rather
            // than just showing "awaiting review" with no reason.
            'pre_moderated' => $member->isPreModerated(),
            'may_post_links' => $member->mayPostLinks(),
            'status' => $member->status,
            // Everyone registers as `user`; the front end renders a badge only
            // for anything else.
            'role' => $member->role,
        ];
    }

    private function packedIp(Request $request): ?string
    {
        $ip = (string) $request->ip();

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : null;
    }

    private function site(): Site
    {
        /** @var Site $site */
        $site = app('current_site');

        // Accounts, not the board. These endpoints are how somebody GETS an
        // account, and a site may offer that before its discussions open —
        // so they follow `accounts_enabled`, which the board implies.
        abort_unless($site->allowsAccounts(), Response::HTTP_NOT_FOUND);

        return $site;
    }
}
