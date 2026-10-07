<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\Forum\RegisterForumUserRequest;
use App\Jobs\SendForumAccountEmail;
use App\Mail\ForumAccountEmail;
use App\Models\ForumUser;
use App\Models\Site;
use App\Services\Auth\GoogleIdentityService;
use App\Services\Forum\ForumPasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
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
    /** Members may hold a session on a handful of devices, not unboundedly. */
    private const int MAX_TOKENS = 5;

    public function __construct(private readonly ForumPasswordResetService $resets) {}

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

        $member = ForumUser::create([
            ...$request->safe()->only(['display_name', 'email', 'password']),
            'site_id' => $site->id,
        ]);

        $member->registration_ip = $this->packedIp($request);
        $member->save();

        $link = $this->verificationLink($member, $site);

        // Queued, not sent inline: the response must not wait on SMTP. `high`,
        // because someone is sitting on the registration screen.
        SendForumAccountEmail::dispatch($member->id, ForumAccountEmail::TYPE_VERIFY, $link);

        return response()->json([
            'data' => [
                'registered' => true,
                'message'    => 'Check your email to confirm your address before posting.',
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
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $member = ForumUser::query()
            ->where('site_id', $site->id)
            ->where('email', $credentials['email'])
            ->first();

        // Hash::check against a dummy when the member is missing, so a wrong
        // address and a wrong password cost the same time. Without it the
        // response time alone answers "is this address registered".
        $valid = $member !== null
            ? Hash::check($credentials['password'], $member->password)
            : Hash::check($credentials['password'], '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

        if (! $valid || $member === null) {
            return response()->json(['message' => 'Those details do not match an account.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($member->status === ForumUser::STATUS_BANNED) {
            return response()->json(['message' => 'This account cannot sign in.'], Response::HTTP_FORBIDDEN);
        }

        // Oldest sessions fall off rather than the newest being refused —
        // signing in on a new phone must not fail because of five old tokens.
        $member->tokens()
            ->orderByDesc('id')
            ->skip(self::MAX_TOKENS - 1)
            ->take(PHP_INT_MAX)
            ->get()
            ->each->delete();

        $token = $member->createToken('forum:' . $site->slug)->plainTextToken;

        $member->forceFill(['last_seen_at' => now()])->save();

        return response()->json([
            'data' => [
                'token'  => $token,
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
     * THREE outcomes, and the middle one is the reason this is not just a
     * create:
     *
     *   known Google link  → sign that member in.
     *   known EMAIL        → attach the Google id to the existing account. A
     *                        member who registered with a password and later
     *                        presses the Google button must land in their own
     *                        account, not a second one holding none of their
     *                        posts. The address is Google-verified, so this is
     *                        not a way to seize an account: the person proved
     *                        control of the mailbox the account already names.
     *   neither            → create the account, already verified, because the
     *                        thing the verification email exists to prove has
     *                        just been proven by Google.
     *
     * A created account still gets a password column — it is NOT NULL — so one
     * is generated and discarded. Signing in with it is impossible; "forgot
     * password" is the way across to the password flow.
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

        $member = ForumUser::query()
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('google_id', $identity['sub'])->orWhere('email', $identity['email']))
            // A row matching on google_id wins over one matching only on email,
            // which matters in the rare case where the address moved between
            // two Google accounts.
            ->orderByRaw('google_id = ? DESC', [$identity['sub']])
            ->first();

        if ($member === null) {
            $member = new ForumUser([
                'site_id'      => $site->id,
                'display_name' => $this->displayNameFor($identity),
                'email'        => $identity['email'],
                'password'     => Str::password(32),
            ]);
        }

        if ($member->status === ForumUser::STATUS_BANNED) {
            return response()->json(['message' => 'This account cannot sign in.'], Response::HTTP_FORBIDDEN);
        }

        $member->forceFill([
            'google_id' => $identity['sub'],
            // Google has verified the address. An account that arrived this way
            // can post immediately, which is the whole appeal of the button.
            'email_verified_at' => $member->email_verified_at ?? now(),
            'last_seen_at'      => now(),
        ])->save();

        // Same session cap as the password path — see login().
        $member->tokens()
            ->orderByDesc('id')
            ->skip(self::MAX_TOKENS - 1)
            ->take(PHP_INT_MAX)
            ->get()
            ->each->delete();

        return response()->json([
            'data' => [
                'token'  => $member->createToken('forum:' . $site->slug)->plainTextToken,
                'member' => $this->profile($member),
            ],
        ]);
    }

    /**
     * A display name for an account being created from a Google profile.
     *
     * Google omits `name` for a profile that has none, and the column is NOT
     * NULL with its own uniqueness handled by the model's slug. The local part
     * of the address is the fallback — never the full address, which would put
     * somebody's email on every post they write.
     *
     * @param  array{sub: string, email: string, name: string}  $identity
     */
    private function displayNameFor(array $identity): string
    {
        $name = trim($identity['name']);

        if ($name !== '') {
            return mb_substr($name, 0, 60);
        }

        $local = Str::before($identity['email'], '@');

        return mb_substr($local === '' ? 'Member' : $local, 0, 60);
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

        $account = ForumUser::query()
            ->where('site_id', $current->id)
            ->whereKey($member)
            ->first();

        abort_if($account === null, Response::HTTP_NOT_FOUND);

        if ($account->email_verified_at === null) {
            $account->forceFill(['email_verified_at' => now()])->save();
        }

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
            'email'   => ['required', 'email', 'max:255'],
            'website' => ['present', 'max:0'],
        ]);

        $token = $this->resets->issue((int) $site->id, $data['email']);

        /*
         * Mail only when there IS an account.
         *
         * The RESPONSE is identical either way — see the docblock — but there is
         * nobody to mail when the address is unregistered, and inventing a send
         * would be worse than useless. The timing difference between "queued a
         * job" and "did not" is far below the noise of an HTTP round trip, which
         * is what keeps this from becoming an enumeration oracle.
         */
        if ($token !== null) {
            $member = ForumUser::query()
                ->where('site_id', $site->id)
                ->where('email', $data['email'])
                ->first();

            if ($member !== null) {
                SendForumAccountEmail::dispatch(
                    $member->id,
                    ForumAccountEmail::TYPE_RESET,
                    $site->frontendBaseUrl() . '/forum/account/reset?token=' . urlencode($token)
                        . '&email=' . urlencode($data['email']),
                    ForumPasswordResetService::EXPIRY_MINUTES,
                );
            }
        }

        return response()->json([
            'data' => [
                'sent'       => true,
                'message'    => 'If that address has an account, a reset link is on its way.',
                'reset_token' => app()->environment('local', 'testing') ? $token : null,
            ],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $site = $this->site();

        $data = $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'token'    => ['required', 'string'],
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
            'id'            => (int) $member->id,
            'display_name'  => $member->display_name,
            'slug'          => $member->slug,
            'avatar_path'   => $member->avatar_path,
            'posts_count'   => (int) $member->approved_posts_count,
            'verified'      => $member->email_verified_at !== null,
            // The front end uses these to explain WHY a reply is held rather
            // than just showing "awaiting review" with no reason.
            'pre_moderated' => $member->isPreModerated(),
            'may_post_links' => $member->mayPostLinks(),
            'status'        => $member->status,
            // Everyone registers as `user`; the front end renders a badge only
            // for anything else.
            'role'          => $member->role,
        ];
    }

    /**
     * The confirmation link that goes in the email.
     *
     * Points at the SITE, not at the API.
     *
     * It used to be the signed API URL itself, which was broken two ways at
     * once: that route is POST-only (mail clients prefetch GET links, which
     * would confirm addresses nobody clicked), so clicking it produced a raw
     * "The GET method is not supported" JSON error; and the host was the
     * shared API domain, so a winpalack member was sent to a page branded as
     * another site entirely.
     *
     * So the member goes to this site's own /register/verify page, which POSTs
     * back to the signed route server-side — exactly the shape the newsletter
     * double opt-in already uses (see SiteVerifyEmail::verifyUrl). It sits
     * under /register because confirming the address is the last step of
     * registering, not a forum page.
     *
     * `expires` and `signature` are carried across UNTOUCHED and in the order
     * Laravel emitted them: the signature is computed over the API URL and its
     * query string, so the page must rebuild that URL byte-for-byte or
     * hasValidSignature() rejects it. Nothing may be added, removed or
     * reordered here.
     *
     * The route lives under the public `sites/{site}` prefix, so the site slug
     * is a required segment — signing the URL without it throws rather than
     * producing a broken link, which is how the original bug was caught.
     */
    private function verificationLink(ForumUser $member, Site $site): string
    {
        $signed = URL::temporarySignedRoute(
            'forum.verify',
            now()->addDays(7),
            ['site' => $site->slug, 'member' => $member->id],
        );

        $query = (string) parse_url($signed, PHP_URL_QUERY);

        return $site->frontendBaseUrl()
            . '/register/verify?member=' . $member->id
            . ($query === '' ? '' : '&' . $query);
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
