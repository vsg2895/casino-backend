<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Checks that a Google ID token is genuine, current, and meant for US.
 *
 * ── Why Google's endpoint rather than local JWKS verification ───────────────
 *
 * An ID token is a signed JWT, and the textbook check is to fetch Google's
 * public keys and verify RS256 locally. This application has no JWT library,
 * and building an RSA key from a JWK by hand is security-sensitive code written
 * once and reviewed never. Google publishes `tokeninfo` for exactly this case:
 * it validates the signature and expiry and returns the decoded claims.
 *
 * The cost is one outbound HTTPS call per sign-in and a dependency on Google
 * being reachable — which is not a real loss, because the browser could not have
 * obtained a credential if Google were down. Moving to local verification later
 * is a change to this one method and nothing else.
 *
 * ── What is verified, and why each one matters ──────────────────────────────
 *
 *  signature + expiry   by Google, in the call itself.
 *  aud  === our client  WITHOUT this the endpoint accepts a token minted for
 *                       ANY Google app — anyone with a client id of their own
 *                       could sign in as any of our members. This is the check
 *                       that makes the rest of it safe.
 *  iss  is Google       the only two values Google issues.
 *  email_verified       a Google account can carry an unverified address, and
 *                       accepting one would let somebody claim an address they
 *                       do not control — which is the whole point of the
 *                       double-opt-in flow on the password path.
 *
 * Returns null for anything that fails. The caller turns that into one generic
 * message: naming which check failed tells an attacker what to fix.
 */
class GoogleIdentityService
{
    private const string TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    /** @var list<string> The only issuers Google uses for ID tokens. */
    private const array ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** Seconds before the call is abandoned. Someone is waiting on a button. */
    private const int TIMEOUT = 5;

    public function configured(): bool
    {
        return $this->clientId() !== '';
    }

    /**
     * The identity behind an ID token, or null if it cannot be trusted.
     *
     * @return array{sub: string, email: string, name: string}|null
     */
    public function verify(string $idToken): ?array
    {
        $clientId = $this->clientId();

        if ($clientId === '' || trim($idToken) === '') {
            return null;
        }

        try {
            $response = Http::timeout(self::TIMEOUT)
                ->acceptJson()
                ->get(self::TOKENINFO_URL, ['id_token' => $idToken]);
        } catch (\Throwable $e) {
            // A network failure is not a bad token; it is our problem, and it
            // is worth a log line. The member still gets a generic refusal.
            Log::warning('Google tokeninfo unreachable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $claims = $response->json();

        if (! is_array($claims)) {
            return null;
        }

        $audience = (string) ($claims['aud'] ?? '');
        $issuer = (string) ($claims['iss'] ?? '');
        // Google sends this as the STRING "true", not a boolean, so a strict
        // comparison against true would reject every valid token.
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $email = trim((string) ($claims['email'] ?? ''));
        $subject = trim((string) ($claims['sub'] ?? ''));

        if (! hash_equals($clientId, $audience)) {
            return null;
        }

        if (! in_array($issuer, self::ISSUERS, true)) {
            return null;
        }

        if (! $emailVerified || $email === '' || $subject === '') {
            return null;
        }

        return [
            'sub'   => $subject,
            'email' => mb_strtolower($email),
            // Google omits `name` when the person has no profile name set, so
            // the caller must have a fallback rather than an empty display name.
            'name'  => trim((string) ($claims['name'] ?? '')),
        ];
    }

    private function clientId(): string
    {
        return trim((string) config('services.google.client_id', ''));
    }
}
