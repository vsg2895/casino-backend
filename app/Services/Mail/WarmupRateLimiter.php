<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * A ROLLING send-rate window for warmup, shared by every worker.
 *
 * WHY NOT A FIXED DELAY. `sleep(6)` between messages is not this. A fixed delay
 * spaces sends out evenly but enforces no ceiling: two workers each sleeping six
 * seconds send twenty messages a minute between them, and after a stall the
 * backlog goes out with no pause at all. The rule warmup needs is an upper bound
 * over any sixty-second stretch, which is a property of the WINDOW, not of the
 * gap between two sends.
 *
 * WHY NOT Laravel's RateLimiter or Redis::throttle. Both are FIXED windows: a
 * counter with a decay. Ten sends at 12:00:59 and ten more at 12:01:01 satisfy
 * "10 per minute" on a fixed window and put twenty messages into a two-second
 * stretch — precisely the burst a warming mailbox must not produce.
 *
 * HOW. A sliding window LOG: the timestamps of the last `max` sends, pruned to
 * the window on every read. A slot exists while fewer than `max` timestamps
 * remain inside it; otherwise the wait is exactly how long until the OLDEST of
 * them falls out. That is the definition the requirement states.
 *
 * WHERE THE LOG LIVES. In the cache store, under ONE key, because warmup fans
 * out into independent queue jobs ({@see \App\Jobs\SendWarmupBatchJob}) and any
 * number of workers may hold one at the same time. A per-process limiter would
 * therefore enforce `max` PER WORKER — four workers, forty messages a minute.
 * With `CACHE_STORE=redis` (this project's local and production setting) the log
 * and the lock that guards it are both in Redis, so the ceiling is global. With
 * a process-local store — `array`, as under test — the limiter degrades to a
 * per-process window, which is only correct when one process sends. See
 * isCentralised().
 *
 * READ-MODIFY-WRITE, GUARDED. Pruning, counting and appending must be one
 * indivisible step, or two workers both read nine and both append. Every store
 * this project can be configured with (redis, database, file, memcached, array)
 * implements {@see LockProvider}, so the sequence runs under an atomic lock
 * rather than needing a store-specific script. At ten sends a minute the lock is
 * held for microseconds and contention is theoretical.
 *
 * CLOCK. `Carbon::now()`, never `microtime()` — so `Carbon::setTestNow()` and
 * `Sleep::fake(syncWithCarbon: true)` drive the window deterministically in
 * tests, and a test that would otherwise wait a real minute finishes instantly.
 *
 * NOT A DELIVERY GUARANTEE. The limiter decides WHEN a send may be attempted; it
 * records nothing about the outcome. Whether the message left is the sender's
 * business, and a failed send still consumed its slot — which is correct, because
 * the remote host saw the connection either way.
 */
final class WarmupRateLimiter
{
    /** The ONE key every warmup sender shares. */
    public const string WINDOW_KEY = 'warmup:rate-window';

    private const string LOCK_KEY = 'warmup:rate-window:lock';

    /** Held only for the read-modify-write; long enough that a GC pause cannot orphan it. */
    private const int LOCK_SECONDS = 10;

    /** How long a worker queues for the lock before proceeding unguarded. */
    private const int LOCK_WAIT_SECONDS = 5;

    /**
     * Fallbacks, used when config is unavailable or nonsensical.
     *
     * A zero or negative configured limit falls back to these rather than
     * meaning "unlimited". This limiter exists to protect a mailbox's
     * reputation, and a typo in `.env` must not be able to switch that
     * protection off silently.
     */
    private const int MAX_EMAILS = 10;

    private const int WINDOW_SECONDS = 60;

    /** Never spin: a wait rounded down to zero still yields to the clock. */
    private const float MIN_SLEEP_SECONDS = 0.05;

    /** Guards the "proceeded without the lock" warning so it is logged once per process. */
    private static bool $lockWarningLogged = false;

    public function __construct(private readonly Repository $cache) {}

    /** Messages permitted per window. */
    public function max(): int
    {
        $value = (int) config('warmup.rate_limit.max_emails', self::MAX_EMAILS);

        return $value > 0 ? $value : self::MAX_EMAILS;
    }

    /** Length of the rolling window, in seconds. */
    public function windowSeconds(): int
    {
        $value = (int) config('warmup.rate_limit.window_seconds', self::WINDOW_SECONDS);

        return $value > 0 ? $value : self::WINDOW_SECONDS;
    }

    /**
     * Is the ceiling global, or only this process's?
     *
     * False means the configured cache store keeps its data inside this PHP
     * process (`array`), so a second worker would get its own window. Callers
     * that can run concurrently should say so in their logs rather than imply a
     * guarantee that does not hold.
     */
    public function isCentralised(): bool
    {
        return ! $this->cache->getStore() instanceof ArrayStore;
    }

    /**
     * Block until a slot is free, then take it.
     *
     * Returns the decision that granted the slot, so the caller can log the
     * window occupancy without asking a second question.
     *
     * `$onWait` is invoked before each wait with the refusing decision and the
     * seconds about to be slept — the caller owns the log line, because only it
     * knows which run and batch this is.
     *
     * @param  (callable(RateLimitDecision, float): void)|null  $onWait
     */
    public function acquire(?callable $onWait = null): RateLimitDecision
    {
        while (true) {
            $decision = $this->attempt();

            if ($decision->allowed) {
                return $decision;
            }

            // A refusal can never need longer than the window itself: the oldest
            // timestamp in it is at most `window` seconds old. Clamping means a
            // corrupt or clock-skewed entry delays one message rather than
            // parking a worker indefinitely.
            $wait = min($decision->waitSeconds, (float) $this->windowSeconds());
            $wait = max($wait, self::MIN_SLEEP_SECONDS);

            if ($onWait !== null) {
                $onWait($decision, $wait);
            }

            // Through the Sleep facade, not sleep()/usleep(), so tests fake it.
            Sleep::usleep((int) ceil($wait * 1_000_000));
        }
    }

    /**
     * Take a slot if the window has room. Never blocks.
     *
     * An allowed decision has already recorded the send.
     */
    public function attempt(): RateLimitDecision
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            // No store this project supports lands here. If one ever does, a
            // slightly loose window beats refusing to send at all.
            return $this->decide();
        }

        $lock = $store->lock(self::LOCK_KEY, self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            $this->warnOnceAboutLock();

            return $this->decide();
        } catch (Throwable $e) {
            // An unreachable cache store must not stop a warmup run; the window
            // read below will fail open for the same reason.
            $this->warnOnceAboutLock($e->getMessage());

            return $this->decide();
        }

        try {
            return $this->decide();
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Current occupancy, without taking a slot. Diagnostics only.
     */
    public function used(): int
    {
        return count($this->window((int) Carbon::now()->getPreciseTimestamp(3)));
    }

    /** Drop the window. For tests and for an operator starting a run from clean. */
    public function reset(): void
    {
        try {
            $this->cache->forget(self::WINDOW_KEY);
        } catch (Throwable $e) {
            Log::warning('[Warmup] Could not reset the rate-limit window', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Prune, count, and append if there is room.
     *
     * Must run under the lock — see the class docblock.
     */
    private function decide(): RateLimitDecision
    {
        $max = $this->max();
        $windowMs = $this->windowSeconds() * 1000;
        $nowMs = (int) Carbon::now()->getPreciseTimestamp(3);

        $timestamps = $this->window($nowMs);

        if (count($timestamps) < $max) {
            $timestamps[] = $nowMs;
            $this->store($timestamps);

            return RateLimitDecision::allowed(count($timestamps), $max);
        }

        // Refused. The earliest free moment is when the oldest recorded send
        // leaves the window — the sliding-window rule, stated directly.
        $oldest = $timestamps[0];
        $waitMs = ($oldest + $windowMs) - $nowMs;

        return RateLimitDecision::refused(count($timestamps), $max, $waitMs / 1000);
    }

    /**
     * The send timestamps still inside the window, oldest first.
     *
     * @return list<int>  Milliseconds.
     */
    private function window(int $nowMs): array
    {
        $cutoff = $nowMs - ($this->windowSeconds() * 1000);

        try {
            $stored = $this->cache->get(self::WINDOW_KEY, []);
        } catch (Throwable $e) {
            // Fail OPEN deliberately. A warmup run that cannot read its window is
            // a warmup run that cannot send, and a stalled reputation build is a
            // worse outcome than one unthrottled minute. Logged, not silent.
            $this->warnOnceAboutLock($e->getMessage());

            return [];
        }

        if (! is_array($stored)) {
            return [];
        }

        $timestamps = [];

        foreach ($stored as $value) {
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            $ms = (int) $value;

            // Also drops anything dated in the future, which a clock correction
            // on one app server could otherwise leave wedged in the window.
            if ($ms > $cutoff && $ms <= $nowMs) {
                $timestamps[] = $ms;
            }
        }

        sort($timestamps);

        return $timestamps;
    }

    /** @param list<int> $timestamps */
    private function store(array $timestamps): void
    {
        // One window past the newest entry: every timestamp is expired by then, so
        // an abandoned key disappears on its own rather than having to be cleaned
        // up. Never no-expiry — that would leave a dead window in Redis for good.
        $ttl = $this->windowSeconds() + 60;

        try {
            $this->cache->put(self::WINDOW_KEY, $timestamps, $ttl);
        } catch (Throwable $e) {
            // The slot is not recorded, so the window under-counts by one. Same
            // fail-open trade as window(): sending continues.
            $this->warnOnceAboutLock($e->getMessage());
        }
    }

    private function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $e) {
            // The lock's own TTL frees it in at most LOCK_SECONDS.
            Log::warning('[Warmup] Could not release the rate-limit lock', ['error' => $e->getMessage()]);
        }
    }

    /**
     * One warning per process, not one per message.
     *
     * At ten messages a minute an unreachable cache would otherwise write a log
     * line every six seconds for the length of the run.
     */
    private function warnOnceAboutLock(?string $error = null): void
    {
        if (self::$lockWarningLogged) {
            return;
        }

        self::$lockWarningLogged = true;

        Log::warning('[Warmup] Rate-limit window is degraded; sending continues without a guaranteed ceiling', [
            'error' => $error,
        ]);
    }
}
