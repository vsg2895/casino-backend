<?php

declare(strict_types=1);

namespace App\Services\Forum;

use App\Http\Controllers\Api\Public\ForumAuthController;
use App\Jobs\SendForumAccountEmail;
use App\Mail\ForumAccountEmail;
use App\Models\ForumUser;
use App\Models\Site;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Forum member accounts: creating them, checking credentials, issuing sessions,
 * confirming addresses and starting password resets.
 *
 * Every method takes the Site resolved by VerifySiteAccess — an account is valid
 * on one domain, which is the decision recorded in the forum_users migration.
 * HTTP concerns (status codes, the feature gate, the response body) stay in
 * {@see ForumAuthController}.
 */
class ForumAccountService
{
    /** Members may hold a session on a handful of devices, not unboundedly. */
    public const int MAX_TOKENS = 5;

    /**
     * A bcrypt hash of nothing in particular, checked when the address is
     * unknown so a wrong address and a wrong password cost the same time.
     */
    private const string DUMMY_HASH = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(private readonly ForumPasswordResetService $resets) {}

    /**
     * Create an unconfirmed account and queue its confirmation email.
     *
     * @param  array{display_name: string, email: string, password: string}  $fields
     * @return string The confirmation link that was mailed.
     */
    public function register(Site $site, array $fields, ?string $packedIp, bool $marketingConsent): string
    {
        $member = ForumUser::create([
            ...$fields,
            'site_id' => $site->id,
        ]);

        $member->registration_ip = $packedIp;

        /*
         * Consent, stamped at the moment it was given.
         *
         * Written here rather than through `create()` above because it is not
         * fillable: a registration POST must not be able to set an arbitrary
         * timestamp, and `true` is the only thing a client gets to say. NULL
         * stays NULL when the box was left alone, and NULL is what the
         * post-verification promotion sweep reads as "do not market to this
         * person".
         */
        if ($marketingConsent) {
            $member->marketing_consent_at = now();
        }

        $member->save();

        $link = $this->verificationLink($member, $site);

        // Queued, not sent inline: the response must not wait on SMTP. `high`,
        // because someone is sitting on the registration screen.
        SendForumAccountEmail::dispatch($member->id, ForumAccountEmail::TYPE_VERIFY, $link);

        return $link;
    }

    /**
     * The member these credentials belong to, or null.
     *
     * Null for a bad address and a bad password alike, and at the same cost:
     * Hash::check runs against a dummy when the member is missing, so the
     * response time alone cannot answer "is this address registered".
     */
    public function memberForCredentials(Site $site, string $email, string $password): ?ForumUser
    {
        $member = ForumUser::query()
            ->where('site_id', $site->id)
            ->where('email', $email)
            ->first();

        $valid = $member !== null
            ? Hash::check($password, $member->password)
            : Hash::check($password, self::DUMMY_HASH);

        return $valid ? $member : null;
    }

    /**
     * The account a verified Google identity signs in to — existing or new.
     *
     *   known Google link  → that member.
     *   known EMAIL        → the existing account, which the Google id is then
     *                        attached to. A member who registered with a password
     *                        and later presses the Google button must land in
     *                        their own account. The address is Google-verified,
     *                        so this is not a way to seize an account: the person
     *                        proved control of the mailbox the account names.
     *   neither            → a new, UNSAVED account. It still gets a password
     *                        column — it is NOT NULL — so one is generated and
     *                        discarded; "forgot password" is the way across.
     *
     * @param  array{sub: string, email: string, name: string}  $identity
     */
    public function memberForGoogle(Site $site, array $identity): ForumUser
    {
        $member = ForumUser::query()
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->where('google_id', $identity['sub'])->orWhere('email', $identity['email']))
            // A row matching on google_id wins over one matching only on email,
            // which matters in the rare case where the address moved between
            // two Google accounts.
            ->orderByRaw('google_id = ? DESC', [$identity['sub']])
            ->first();

        return $member ?? new ForumUser([
            'site_id' => $site->id,
            'display_name' => $this->displayNameFor($identity),
            'email' => $identity['email'],
            'password' => Str::password(32),
        ]);
    }

    /**
     * Link the Google id and save, creating the account if it is new.
     *
     * @param  array{sub: string, email: string, name: string}  $identity
     */
    public function linkGoogle(ForumUser $member, array $identity): void
    {
        $member->forceFill([
            'google_id' => $identity['sub'],
            // Google has verified the address. An account that arrived this way
            // can post immediately, which is the whole appeal of the button.
            'email_verified_at' => $member->email_verified_at ?? now(),
            'last_seen_at' => now(),
        ])->save();
    }

    /**
     * A new session token, after dropping the oldest beyond the cap.
     *
     * Oldest sessions fall off rather than the newest being refused — signing
     * in on a new phone must not fail because of five old tokens.
     */
    public function issueToken(ForumUser $member, Site $site): string
    {
        $member->tokens()
            ->orderByDesc('id')
            ->skip(self::MAX_TOKENS - 1)
            ->take(PHP_INT_MAX)
            ->get()
            ->each->delete();

        return $member->createToken('forum:'.$site->slug)->plainTextToken;
    }

    /**
     * Mark a member's address confirmed. Idempotent: confirming twice is
     * harmless, which is why the link is signed rather than single-use.
     *
     * @return bool False when no such member exists on this site.
     */
    public function confirm(Site $site, int $memberId): bool
    {
        $account = ForumUser::query()
            ->where('site_id', $site->id)
            ->whereKey($memberId)
            ->first();

        if ($account === null) {
            return false;
        }

        if ($account->email_verified_at === null) {
            $account->forceFill(['email_verified_at' => now()])->save();
        }

        return true;
    }

    /**
     * Issue a reset token and mail it, when the address has an account.
     *
     * The caller answers identically either way. There is nobody to mail when
     * the address is unregistered, and inventing a send would be worse than
     * useless; the timing difference between "queued a job" and "did not" is
     * far below the noise of an HTTP round trip.
     *
     * @return string|null The plain token, or null when nothing was issued.
     */
    public function sendPasswordReset(Site $site, string $email): ?string
    {
        $token = $this->resets->issue((int) $site->id, $email);

        if ($token !== null) {
            $member = ForumUser::query()
                ->where('site_id', $site->id)
                ->where('email', $email)
                ->first();

            if ($member !== null) {
                SendForumAccountEmail::dispatch(
                    $member->id,
                    ForumAccountEmail::TYPE_RESET,
                    $site->frontendBaseUrl().'/forum/account/reset?token='.urlencode($token)
                        .'&email='.urlencode($email),
                    ForumPasswordResetService::EXPIRY_MINUTES,
                );
            }
        }

        return $token;
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
            .'/register/verify?member='.$member->id
            .($query === '' ? '' : '&'.$query);
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
}
