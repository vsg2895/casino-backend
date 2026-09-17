<?php

declare(strict_types=1);

namespace App\Services\UniOne;

use App\Models\UniOne\UniOneApiKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reads, caches and verifies UniOne keys, and owns the single-default rule.
 */
class UniOneKeyService
{
    private const CACHE_PREFIX = 'unione:key:';

    private const DEFAULT_CACHE = 'unione:key:default';

    private const TTL_MINUTES = 60;

    /**
     * The default key, cached.
     *
     * The brief asks that the key not be fetched from the database on every
     * send. The cached value is the MODEL, so the encrypted attribute is
     * decrypted on access exactly as it would be from a fresh read — the
     * ciphertext is what sits in the cache, never the plaintext.
     */
    public function default(): ?UniOneApiKey
    {
        try {
            return Cache::remember(
                self::DEFAULT_CACHE,
                now()->addMinutes(self::TTL_MINUTES),
                static fn () => UniOneApiKey::query()->active()->default()->first(),
            );
        } catch (Throwable) {
            // A cache outage must never stop sending.
            return UniOneApiKey::query()->active()->default()->first();
        }
    }

    public function find(int $id): ?UniOneApiKey
    {
        try {
            return Cache::remember(
                self::CACHE_PREFIX . $id,
                now()->addMinutes(self::TTL_MINUTES),
                static fn () => UniOneApiKey::query()->active()->whereKey($id)->first(),
            );
        } catch (Throwable) {
            return UniOneApiKey::query()->active()->whereKey($id)->first();
        }
    }

    /**
     * Busted on every write.
     *
     * Called from the controller after create, update, delete, toggle and
     * set-default — a stale key in cache would keep sending with a rotated
     * credential until the TTL expired.
     */
    public function forget(?int $id = null): void
    {
        try {
            Cache::forget(self::DEFAULT_CACHE);

            if ($id !== null) {
                Cache::forget(self::CACHE_PREFIX . $id);
            }
        } catch (Throwable) {
            // Nothing to do — the TTL will clear it.
        }
    }

    /**
     * Make one key the default, in a transaction.
     *
     * Two rules, both enforced here rather than by the caller:
     *   - exactly one row holds `is_default`
     *   - a key that has not passed verification cannot become default
     *
     * The second is the brief's requirement and it matters: a default key is the
     * one every send reaches for, so promoting an unverified credential would
     * fail every send rather than one.
     *
     * @throws ValidationException
     */
    public function makeDefault(UniOneApiKey $key): UniOneApiKey
    {
        if (! $key->is_active) {
            throw ValidationException::withMessages([
                'is_default' => 'An inactive key cannot be the default.',
            ]);
        }

        if (! $key->isVerified()) {
            throw ValidationException::withMessages([
                'is_default' => 'Verify this key before making it the default.',
            ]);
        }

        DB::transaction(function () use ($key): void {
            // Clear first, then set. The reverse order leaves a window where two
            // rows are default and a concurrent send could read either.
            UniOneApiKey::query()->where('is_default', true)->whereKeyNot($key->id)->update(['is_default' => false]);
            UniOneApiKey::query()->whereKey($key->id)->update(['is_default' => true]);
        });

        $this->forget($key->id);

        return $key->refresh();
    }

    /**
     * Call /system/ping.json and /system/info.json and record the result.
     *
     * Stored as a short string rather than a boolean so the list can show WHY a
     * key failed without a second request.
     *
     * @return array{ok: bool, status: string, info: array<string, mixed>|null}
     */
    public function verify(UniOneApiKey $key): array
    {
        $client = new UniOneClient($key);

        $ping = $client->ping();

        if (! $ping->ok) {
            $status = $this->describeFailure($ping->httpStatus, $ping->apiErrorCode, $ping->message);
            $key->forceFill(['last_verified_at' => now(), 'last_verify_status' => $status])->save();
            $this->forget($key->id);

            return ['ok' => false, 'status' => $status, 'info' => null];
        }

        $info = $client->info();
        $details = $info->ok ? $info->raw : [];

        // "ok" prefix is what isVerified() checks — see the model.
        $status = 'ok';

        if (isset($details['email'])) {
            $status .= ' · ' . $details['email'];
        }

        if (isset($details['project_name'])) {
            $status .= ' · project ' . $details['project_name'];
        }

        $key->forceFill([
            'last_verified_at'   => now(),
            'last_verify_status' => mb_substr($status, 0, 255),
        ])->save();

        $this->forget($key->id);

        return ['ok' => true, 'status' => $status, 'info' => $details];
    }

    /** A readable one-liner for the list, with no secret in it. */
    private function describeFailure(int $http, ?int $code, ?string $message): string
    {
        $parts = array_filter([
            $http === 0 ? 'unreachable' : "HTTP {$http}",
            $code !== null ? "code {$code}" : null,
            $message,
        ]);

        return mb_substr('failed: ' . implode(' · ', $parts), 0, 255);
    }
}
