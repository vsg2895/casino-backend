<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Models\UniOne\UniOneApiKey;
use App\Support\UniOne\UniOneResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The HTTP client for one UniOne key.
 *
 * ── The key never appears in a log ──────────────────────────────────────────
 *
 * It goes into the `X-API-KEY` header and nowhere else. Every log line here
 * identifies the key by its database id and name; the decrypted value is never
 * interpolated into a message, an exception or a context array. That is the one
 * rule this class exists to keep.
 *
 * ── 429 slows the whole queue, not just this job ────────────────────────────
 *
 * The brief asks for that explicitly, and a per-job backoff cannot deliver it:
 * ten workers each backing off individually still hammer UniOne ten times. The
 * cooldown is written to the SHARED cache under a per-key name, and every worker
 * checks it before calling. One 429 quietens all of them.
 */
class UniOneClient
{
    /** Cache key prefix for the shared rate-limit cooldown. */
    private const COOLDOWN_PREFIX = 'unione:cooldown:';

    /** Seconds to quieten every worker for after a 429 with no Retry-After. */
    private const DEFAULT_COOLDOWN = 30;

    public function __construct(private readonly UniOneApiKey $key) {}

    public function ping(): UniOneResponse
    {
        return $this->post('system/ping.json', []);
    }

    public function info(): UniOneResponse
    {
        return $this->post('system/info.json', []);
    }

    public function domains(int $limit = 50, int $offset = 0): UniOneResponse
    {
        return $this->post('domain/list.json', ['limit' => $limit, 'offset' => $offset]);
    }

    public function domainDnsRecords(string $domain): UniOneResponse
    {
        return $this->post('domain/get-dns-records.json', ['domain' => $domain]);
    }

    public function validateDkim(string $domain): UniOneResponse
    {
        return $this->post('domain/validate-dkim.json', ['domain' => $domain]);
    }

    public function validateVerificationRecord(string $domain): UniOneResponse
    {
        return $this->post('domain/validate-verification-record.json', ['domain' => $domain]);
    }

    /** @param array<string, mixed> $filters */
    public function suppressions(array $filters = []): UniOneResponse
    {
        return $this->post('suppression/list.json', array_filter($filters, static fn ($v): bool => $v !== null && $v !== ''));
    }

    public function addSuppression(string $email, string $cause): UniOneResponse
    {
        return $this->post('suppression/set.json', ['email' => $email, 'cause' => $cause]);
    }

    public function deleteSuppression(string $email): UniOneResponse
    {
        return $this->post('suppression/delete.json', ['email' => $email]);
    }

    /** @param array<string, mixed> $payload */
    public function setWebhook(array $payload): UniOneResponse
    {
        return $this->post('webhook/set.json', $payload);
    }

    /** @param array<string, mixed> $message */
    public function send(array $message): UniOneResponse
    {
        return $this->post('email/send.json', ['message' => $message]);
    }

    /**
     * Whether every worker is currently told to hold off on this key.
     *
     * Checked by the send job BEFORE it starts work, so a rate-limited key
     * releases the job back to the queue instead of spending an attempt.
     */
    public function isCoolingDown(): bool
    {
        try {
            return Cache::has($this->cooldownKey());
        } catch (Throwable) {
            // A cache outage must not stop sending — it only means the shared
            // brake is unavailable and each job falls back to its own backoff.
            return false;
        }
    }

    public function cooldownSecondsRemaining(): int
    {
        try {
            $until = Cache::get($this->cooldownKey());

            return $until === null ? 0 : max(0, (int) $until - time());
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * One request.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload): UniOneResponse
    {
        $started = hrtime(true);

        try {
            $response = Http::withHeaders([
                    // The documented mechanism. The spec offers no JSON api_key
                    // field, and a header keeps the secret out of any body that
                    // might be logged upstream.
                    'X-API-KEY'    => (string) $this->key->api_key,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->timeout(max(5, (int) $this->key->timeout_seconds))
                // No Laravel-level retry: retries are the JOB's business, so the
                // backoff can be jittered, capped and recorded per attempt.
                ->post($this->key->endpoint($path), $payload);

            $latency = $this->elapsedMs($started);
            $body = $this->decode($response->body());
            $result = UniOneResponse::fromHttp($response->status(), $body, $latency);

            if ($response->status() === 429) {
                $this->startCooldown($response->header('Retry-After'));
            }

            if (! $result->ok) {
                Log::warning('UniOne API error', [
                    // The key by IDENTITY, never by value.
                    'key_id'      => $this->key->id,
                    'key_name'    => $this->key->name,
                    'path'        => $path,
                    'http_status' => $result->httpStatus,
                    'api_code'    => $result->apiErrorCode,
                    'message'     => $result->message,
                ]);
            }

            return $result;
        } catch (Throwable $e) {
            Log::warning('UniOne transport failure', [
                'key_id'  => $this->key->id,
                'path'    => $path,
                'error'   => $e->getMessage(),
            ]);

            return UniOneResponse::transportFailure($e->getMessage(), $this->elapsedMs($started));
        }
    }

    /**
     * Quieten every worker using this key.
     *
     * Honours `Retry-After` when UniOne sends one; otherwise a flat default. The
     * value stored is an absolute timestamp so a worker can report how long is
     * left rather than only whether to wait.
     */
    private function startCooldown(?string $retryAfter): void
    {
        $seconds = is_numeric($retryAfter) ? max(1, (int) $retryAfter) : self::DEFAULT_COOLDOWN;

        try {
            Cache::put($this->cooldownKey(), time() + $seconds, now()->addSeconds($seconds));

            Log::warning('UniOne rate limited — pausing every worker for this key', [
                'key_id'  => $this->key->id,
                'seconds' => $seconds,
            ]);
        } catch (Throwable) {
            // See isCoolingDown(): degraded, not fatal.
        }
    }

    private function cooldownKey(): string
    {
        return self::COOLDOWN_PREFIX . $this->key->id;
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        // A non-JSON body (an HTML 502 from a proxy, say) is still a real
        // failure and must not throw here — it becomes a response with no code.
        return is_array($decoded) ? $decoded : [];
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1e6);
    }
}
