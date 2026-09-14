<?php

declare(strict_types=1);

namespace App\Services\Validation;

use App\Models\EmailValidationLog;
use App\Support\Validation\EmailValidationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SendGrid Email Address Validation, used by the public subscribe endpoint and
 * NOWHERE ELSE.
 *
 * It must never throw and never block a subscription: a SendGrid outage across
 * six live sites has to look like the day before this feature shipped. Every
 * failure path returns a "no result" {@see EmailValidationResult}, which the
 * caller treats as fail-open. There is no code path here that turns an error
 * into a rejection.
 *
 * The key is read from config only, never logged, never returned. A fake or
 * missing key is a skip, not a crash — the placeholder in .env is expected to be
 * live in production until the real key is pasted in.
 */
class SendGridEmailValidationService
{
    private const string ENDPOINT = 'https://api.sendgrid.com/v3/validations/email';

    /** Cache key holding SendGrid's own reported balance, for the admin widget. */
    public const string RATE_LIMIT_CACHE_KEY = 'sendgrid:validation:rate-limit';

    /**
     * Validate one address.
     *
     * The cheap outs come first and cost nothing: disabled, no key, cache hit,
     * quota gone. Only past all of those is a credit spent.
     */
    public function validate(string $email, string $siteSlug): EmailValidationResult
    {
        if (! config('services.sendgrid_validation.enabled')) {
            return EmailValidationResult::skipped('disabled');
        }

        $key = (string) config('services.sendgrid_validation.key');

        // A blank key is a misconfiguration, not a verdict. Logged once per call
        // WITHOUT the value so the operator can see it, but never the secret.
        if (trim($key) === '') {
            Log::warning('SendGrid validation skipped: no validation key configured');

            return EmailValidationResult::skipped('missing_key');
        }

        if (($cached = $this->cached($email)) !== null) {
            return $cached;
        }

        // Cheaper than the quota count (a cache read vs a COUNT) and cheaper
        // still than the call, so it sits here in the cost-control order:
        // syntax -> honeypot/throttle -> already subscribed -> cache -> cooldown
        // -> quota -> SendGrid.
        if ($this->onCooldown($email)) {
            return EmailValidationResult::skipped('email_cooldown');
        }

        if ($this->quotaExhausted()) {
            Log::warning('SendGrid validation skipped: monthly quota exhausted', [
                'month' => EmailValidationLog::currentQuotaMonth(),
                'quota' => (int) config('services.sendgrid_validation.monthly_quota'),
            ]);

            return EmailValidationResult::skipped('quota_exhausted');
        }

        // Stamped BEFORE the call, and regardless of how it turns out: the
        // cooldown exists to cap attempts, and a failed attempt still cost a
        // round trip and may still have been billed upstream.
        $this->startCooldown($email);

        $result = $this->call($email, $siteSlug, $key);

        // Only a real verdict is worth remembering. Caching a failure would turn
        // one outage into days of unvalidated subscribes.
        if ($result->hasVerdict()) {
            $this->remember($email, $result);
        }

        return $result;
    }

    /**
     * The HTTP call.
     *
     * Retries ONCE on a connection error only — that is a failure to reach
     * SendGrid at all, so it cannot have cost a credit. A TIMEOUT is never
     * retried: the request may well have been processed and billed, and a retry
     * would both double the visitor's wait and risk a second charge.
     */
    private function call(string $email, string $siteSlug, string $key): EmailValidationResult
    {
        $timeout = max(1, (int) config('services.sendgrid_validation.timeout', 3));
        $started = microtime(true);

        try {
            $response = Http::withToken($key)
                ->timeout($timeout)
                // connectTimeout is separate from timeout: without it a black-holed
                // host can hang for the OS default long past our budget.
                ->connectTimeout($timeout)
                ->retry(2, 100, fn (Throwable $e): bool => $e instanceof ConnectionException, throw: false)
                ->acceptJson()
                ->post(self::ENDPOINT, [
                    'email' => $email,
                    // Carries the site slug so usage is segmentable in SendGrid's
                    // own dashboard, not only in our logs. Sanitised — see below.
                    'source' => self::outboundSource($siteSlug),
                ]);
        } catch (ConnectionException $e) {
            return EmailValidationResult::failed('connection_error: ' . $this->safe($e->getMessage()), null, $this->ms($started));
        } catch (Throwable $e) {
            return EmailValidationResult::failed('exception: ' . $this->safe($e->getMessage()), null, $this->ms($started));
        }

        $latency = $this->ms($started);
        $status = $response->status();

        $this->rememberRateLimit($response->header('X-RateLimit-Remaining'), $response->header('X-RateLimit-Reset'));

        if ($status === 429) {
            Log::warning('SendGrid validation rate-limited (429)');

            return EmailValidationResult::failed('rate_limited', $status, $latency);
        }

        if (! $response->successful()) {
            // 401 = bad key, 403 = key lacks the validation permission,
            // 400 = we sent something malformed. All configuration faults; none
            // says anything about the address.
            //
            // SendGrid's own message is carried through, and that is not a
            // nicety. This branch used to record nothing but 'http_400', which
            // is indistinguishable from every other bad request — the actual
            // cause ("source names can only contain spaces and alphanumeric
            // characters") was sitting in a response body we threw away, and
            // finding it needed a manual curl. The text is SendGrid's, contains
            // no address and no key, and is capped so a runaway body cannot
            // bloat the log row.
            $detail = $this->upstreamError($response);

            Log::warning('SendGrid validation call failed', [
                'status' => $status,
                'error'  => $detail,
            ]);

            return EmailValidationResult::failed(
                $detail === null ? 'http_' . $status : 'http_' . $status . ': ' . $detail,
                $status,
                $latency,
            );
        }

        $payload = $response->json('result');

        if (! is_array($payload) || ! is_string($payload['verdict'] ?? null)) {
            return EmailValidationResult::failed('malformed_response', $status, $latency);
        }

        return EmailValidationResult::verdict(
            verdict: $payload['verdict'],
            score: isset($payload['score']) ? (float) $payload['score'] : null,
            checks: is_array($payload['checks'] ?? null) ? $payload['checks'] : null,
            suggestion: is_string($payload['suggestion'] ?? null) ? $payload['suggestion'] : null,
            httpStatus: $status,
            latencyMs: $latency,
        );
    }

    /**
     * The `source` SendGrid will accept.
     *
     * Its rule, quoted from the 400 it returns: "source names can only contain
     * spaces and alphanumeric characters". `subscribe_nongambles` therefore
     * fails on the underscore, and so would any slug carrying a hyphen — which
     * is most of them, since slugs are kebab-case by convention. Every single
     * validation call was being rejected because of it.
     *
     * Non-alphanumerics collapse to spaces rather than being stripped, so
     * `site-template` reads as "subscribe site template" in SendGrid's dashboard
     * instead of "subscribe sitetemplate".
     *
     * This is ONLY the outbound value. The `source` written to
     * email_validation_logs stays `subscribe_<slug>` / `admin_check_<slug>` —
     * the admin filters and the stats queries group by it.
     */
    private static function outboundSource(string $siteSlug): string
    {
        $source = preg_replace('/[^A-Za-z0-9]+/', ' ', 'subscribe ' . $siteSlug) ?? 'subscribe';

        return mb_substr(trim(preg_replace('/\s+/', ' ', $source) ?? 'subscribe'), 0, 100);
    }

    /**
     * SendGrid's first error message, or null when the body says nothing useful.
     *
     * Never throws: a failed call must not be turned into an exception by the
     * act of reading why it failed.
     */
    private function upstreamError(Response $response): ?string
    {
        try {
            $errors = $response->json('errors');

            $message = is_array($errors) && is_array($errors[0] ?? null)
                ? ($errors[0]['message'] ?? null)
                : null;

            if (! is_string($message) || trim($message) === '') {
                return null;
            }

            return mb_substr(trim($message), 0, 300);
        } catch (Throwable) {
            return null;
        }
    }

    /** Is this verdict on the configured allow list? */
    public function isAllowed(string $verdict): bool
    {
        /** @var list<string> $allowed */
        $allowed = (array) config('services.sendgrid_validation.allowed_verdicts', []);

        // Case-insensitive: the env value is typed by a human, and "valid" must
        // not silently reject everyone.
        return in_array(
            mb_strtolower($verdict),
            array_map(static fn (string $v): string => mb_strtolower($v), $allowed),
            true,
        );
    }

    /** Real calls billed to the current month. */
    public function usedThisMonth(): int
    {
        return EmailValidationLog::query()
            ->billed()
            ->where('quota_month', EmailValidationLog::currentQuotaMonth())
            ->count();
    }

    public function quotaExhausted(): bool
    {
        $quota = (int) config('services.sendgrid_validation.monthly_quota', 0);

        return $quota > 0 && $this->usedThisMonth() >= $quota;
    }

    /**
     * SendGrid's own reported balance, when it has told us.
     *
     * Kept because our local count and SendGrid's can legitimately disagree —
     * a call billed upstream that timed out on our side is invisible locally.
     *
     * @return array{remaining: int|null, reset: int|null}|null
     */
    public function reportedRateLimit(): ?array
    {
        try {
            /** @var array{remaining: int|null, reset: int|null}|null $v */
            $v = Cache::get(self::RATE_LIMIT_CACHE_KEY);

            return $v;
        } catch (Throwable) {
            return null;
        }
    }

    private function rememberRateLimit(?string $remaining, ?string $reset): void
    {
        if ($remaining === null && $reset === null) {
            return;
        }

        try {
            Cache::put(self::RATE_LIMIT_CACHE_KEY, [
                'remaining' => is_numeric($remaining) ? (int) $remaining : null,
                'reset'     => is_numeric($reset) ? (int) $reset : null,
                'seen_at'   => now()->toIso8601String(),
            ], now()->addDays(35));
        } catch (Throwable) {
            // A cache outage must not fail a subscribe.
        }
    }

    private function cooldownKey(string $email): string
    {
        return 'sendgrid:validation:cooldown:' . hash('xxh128', mb_strtolower(trim($email)));
    }

    private function onCooldown(string $email): bool
    {
        if ((int) config('services.sendgrid_validation.email_cooldown_seconds', 0) < 1) {
            return false;
        }

        try {
            return Cache::has($this->cooldownKey($email));
        } catch (Throwable) {
            // A cache outage must not block a subscribe; it only means the
            // cooldown is not enforced for as long as the cache is down.
            return false;
        }
    }

    private function startCooldown(string $email): void
    {
        $seconds = (int) config('services.sendgrid_validation.email_cooldown_seconds', 0);

        if ($seconds < 1) {
            return;
        }

        try {
            Cache::put($this->cooldownKey($email), true, now()->addSeconds($seconds));
        } catch (Throwable) {
            // Same reasoning as onCooldown().
        }
    }

    private function cacheKey(string $email): string
    {
        // Hashed: a raw address must not become a cache key, where it can end up
        // in slow-log output and key dumps.
        return 'sendgrid:validation:' . hash('xxh128', mb_strtolower(trim($email)));
    }

    private function cached(string $email): ?EmailValidationResult
    {
        try {
            $v = Cache::get($this->cacheKey($email));
        } catch (Throwable) {
            return null;
        }

        if (! is_array($v) || ! is_string($v['verdict'] ?? null)) {
            return null;
        }

        return EmailValidationResult::verdict(
            verdict: $v['verdict'],
            score: isset($v['score']) ? (float) $v['score'] : null,
            checks: is_array($v['checks'] ?? null) ? $v['checks'] : null,
            suggestion: is_string($v['suggestion'] ?? null) ? $v['suggestion'] : null,
            httpStatus: null,
            latencyMs: null,
            wasCached: true,
        );
    }

    private function remember(string $email, EmailValidationResult $result): void
    {
        try {
            Cache::put(
                $this->cacheKey($email),
                $result->toCacheArray(),
                now()->addDays(max(1, (int) config('services.sendgrid_validation.cache_days', 60))),
            );
        } catch (Throwable) {
            // Losing the cache costs a credit next time, not correctness.
        }
    }

    private function ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /** Truncate and strip anything key-shaped out of a message before storing it. */
    private function safe(string $message): string
    {
        return mb_substr(preg_replace('/SG\.[A-Za-z0-9_\-.]+/', '[redacted]', $message) ?? '', 0, 500);
    }
}
