<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWarmupBatchJob;
use App\Models\Site;
use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Models\WarmupSendRecipient;
use App\Services\Mail\EmailTemplateCatalog;
use App\Services\Mail\WarmupMailResolver;
use App\Services\Mail\WarmupRateLimiter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\AssertionFailedError;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Concerns\InteractsWithSites;
use Tests\TestCase;

/**
 * The warmup sending ceiling: at most N messages in ANY rolling window.
 *
 * NO REAL EMAIL LEAVES THIS FILE. Most tests use Mail::fake(); the
 * failure/retry ones swap in a local no-op transport whose doSend() either does
 * nothing or throws, so the real Mailer path runs while nothing reaches the
 * network.
 *
 * HOW TIME WORKS HERE. Real waiting would make this file take a quarter of an
 * hour. `Sleep::fake(syncWithCarbon: true)` records each wait and advances
 * `Carbon::now()` by it instead of sleeping, and the limiter reads that same
 * Carbon clock — so a run that would span twenty minutes of real windows is
 * simulated exactly, and instantly. Nothing about the production path is
 * stubbed: the limiter really prunes, really counts, really refuses.
 *
 * WHERE THE ATTEMPT TIMES COME FROM. `warmup_send_recipients.sent_at`, written
 * for every attempt whether it delivered or not. That is the same audit trail
 * the admin reads, so the assertions are made against what the feature actually
 * recorded rather than against a test-only spy.
 */
class WarmupRateLimitTest extends TestCase
{
    use InteractsWithSites;
    use RefreshDatabase;

    private const int MAX = 10;

    private const int WINDOW = 60;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-26 12:00:00');
        Sleep::fake(syncWithCarbon: true);

        config()->set('warmup.rate_limit.max_emails', self::MAX);
        config()->set('warmup.rate_limit.window_seconds', self::WINDOW);

        // The window lives in the cache; a leftover one would skew the first
        // assertion of every test.
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function seedAddresses(int $count): array
    {
        $rows = [];
        $emails = [];
        $now = Carbon::now();

        for ($i = 1; $i <= $count; $i++) {
            $email = "seed{$i}@example.com";
            $emails[] = $email;
            $rows[] = ['email' => $email, 'created_at' => $now, 'updated_at' => $now];
        }

        // One INSERT: a thousand Eloquent creates would dominate the runtime of a
        // test whose subject is the pacing, not the list.
        foreach (array_chunk($rows, 200) as $chunk) {
            WarmupEmail::insert($chunk);
        }

        return $emails;
    }

    private function warmupRun(Site $site): WarmupSend
    {
        return WarmupSend::create([
            'site_id'         => $site->id,
            'user_id'         => null,
            'template'        => EmailTemplateCatalog::TYPE_PROMOTION,
            'requested_count' => null,
            'cooldown_days'   => null,
        ]);
    }

    /**
     * Run ONE batch through the real job, exactly as a worker would.
     *
     * @param  list<string>  $emails
     */
    private function batch(array $emails, Site $site, WarmupSend $send): void
    {
        (new SendWarmupBatchJob($emails, $site->id, EmailTemplateCatalog::TYPE_PROMOTION, $send->id))
            ->handle(app(WarmupMailResolver::class), app(WarmupRateLimiter::class));
    }

    /**
     * Fan a list out into batches of $chunkSize and run each one, the way
     * SendWarmupCampaignJob does — the point being that the limiter is NOT
     * rebuilt per chunk, because it resolves from the container's shared cache
     * repository every time.
     *
     * @param  list<string>  $emails
     */
    private function fanOut(array $emails, int $chunkSize, Site $site, WarmupSend $send): void
    {
        foreach (array_chunk($emails, $chunkSize) as $payload) {
            $this->batch($payload, $site, $send);
        }
    }

    /**
     * Every recorded attempt time, in epoch seconds, oldest first.
     *
     * @return list<int>
     */
    private function attemptTimes(): array
    {
        return WarmupSendRecipient::query()
            ->orderBy('sent_at')
            ->orderBy('id')
            ->pluck('sent_at')
            ->map(static fn (Carbon $at): int => $at->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * The assertion this whole file exists for: slide a window of $windowSeconds
     * over the attempt times and prove no position holds more than $max.
     *
     * Anchoring a candidate window at every attempt is sufficient — a window
     * holding k attempts can always be slid forward until it starts on one of
     * them without losing any, so the worst case is always tested.
     *
     * @param  list<int>  $times
     */
    private function assertRollingLimit(array $times, int $max = self::MAX, int $windowSeconds = self::WINDOW): void
    {
        $worst = 0;
        $worstAt = null;

        foreach ($times as $start) {
            $count = 0;

            foreach ($times as $at) {
                if ($at >= $start && $at < $start + $windowSeconds) {
                    $count++;
                }
            }

            if ($count > $worst) {
                $worst = $count;
                $worstAt = $start;
            }
        }

        $this->assertLessThanOrEqual(
            $max,
            $worst,
            "at most {$max} sends are permitted in any {$windowSeconds}s window, but "
            .($worstAt === null ? 'none' : Carbon::createFromTimestamp($worstAt)->toDateTimeString())
            ." starts a window holding {$worst}",
        );
    }

    /** A transport that never touches the network; "bad*" addresses throw. */
    private function useLocalTransport(): void
    {
        Mail::extend('warmup-local', fn (array $config): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                foreach ($message->getEnvelope()->getRecipients() as $recipient) {
                    if (str_starts_with($recipient->getAddress(), 'bad')) {
                        throw new RuntimeException('550 mailbox unavailable');
                    }
                }
            }

            public function __toString(): string
            {
                return 'warmup-local://';
            }
        });

        config()->set('mail.mailers.warmup-local', ['transport' => 'warmup-local']);
        config()->set('warmup.mailer', 'warmup-local');
    }

    // ── 0. The assertion itself distinguishes rolling from fixed ─────────────

    public function test_the_assertion_would_reject_a_fixed_window_implementation(): void
    {
        // The one property that separates this feature from the obvious wrong
        // implementations, checked on the checker: ten sends at 12:00:59 and ten
        // more at 12:01:01 satisfy "10 per minute" on a FIXED window — Laravel's
        // RateLimiter, Redis::throttle — and put twenty into a two-second stretch.
        // If assertRollingLimit let that pass, every assertion in this file would
        // be worthless.
        $times = array_merge(
            array_fill(0, self::MAX, Carbon::parse('2026-09-26 12:00:59')->getTimestamp()),
            array_fill(0, self::MAX, Carbon::parse('2026-09-26 12:01:01')->getTimestamp()),
        );

        try {
            $this->assertRollingLimit($times);
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('holding 20', $e->getMessage());

            return;
        }

        $this->fail('a fixed-window send pattern must not satisfy the rolling-window assertion');
    }

    // ── 1. The limit holds, and the chunk size cannot change it ──────────────

    public function test_one_hundred_addresses_in_chunks_of_ten_never_exceed_the_window(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);
        $emails = $this->seedAddresses(100);
        $send = $this->warmupRun($site);

        $this->fanOut($emails, 10, $site, $send);

        $this->assertSame(100, WarmupSendRecipient::count(), 'every address is still processed');
        $this->assertRollingLimit($this->attemptTimes());

        // Chunk behaviour is untouched: ten chunks of ten, each one a batch.
        $this->assertSame(
            100,
            WarmupSendRecipient::query()->where('status', WarmupSendRecipient::STATUS_SENT)->count(),
            'all hundred were delivered, not merely dequeued',
        );
    }

    public function test_a_chunk_of_one_hundred_cannot_bypass_the_limiter(): void
    {
        // The failure mode this guards: a limiter scoped to a chunk would let a
        // single 100-address batch send all hundred at once.
        Mail::fake();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);
        $emails = $this->seedAddresses(100);
        $send = $this->warmupRun($site);

        $this->fanOut($emails, 100, $site, $send);

        $this->assertSame(100, WarmupSendRecipient::count());
        $this->assertRollingLimit($this->attemptTimes());
    }

    public function test_the_pacing_is_identical_whatever_the_chunk_size(): void
    {
        // The strongest statement of "the limiter is independent of chunk size":
        // the same hundred addresses take the same number of windows either way.
        Mail::fake();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);

        $spanFor = function (int $chunkSize) use ($site): int {
            WarmupSendRecipient::query()->delete();
            WarmupEmail::query()->delete();
            Cache::flush();
            Carbon::setTestNow('2026-09-26 12:00:00');

            $emails = $this->seedAddresses(100);
            $this->fanOut($emails, $chunkSize, $site, $this->warmupRun($site));

            $times = $this->attemptTimes();

            return end($times) - $times[0];
        };

        $this->assertSame(
            $spanFor(10),
            $spanFor(100),
            'chunk size must not change how long a hundred addresses take',
        );
    }

    // ── 2. One thousand addresses, and no reset between chunks ───────────────

    public function test_one_thousand_addresses_stay_within_the_window_across_every_chunk(): void
    {
        Mail::fake();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);
        $emails = $this->seedAddresses(1000);
        $send = $this->warmupRun($site);

        $this->fanOut($emails, 100, $site, $send);

        $this->assertSame(1000, WarmupSendRecipient::count(), 'the run reaches every address');
        $this->assertRollingLimit($this->attemptTimes());

        // A limiter rebuilt per chunk would let each of the ten chunks open with a
        // fresh burst of ten, so a thousand addresses would finish in a tenth of
        // the windows. 1000 sends at 10 per window is 99 windows of waiting.
        $times = $this->attemptTimes();
        $span = end($times) - $times[0];

        $this->assertGreaterThanOrEqual(
            99 * self::WINDOW,
            $span,
            'the limiter must stay in force for the whole run, not restart per chunk',
        );
    }

    public function test_the_window_is_carried_from_one_chunk_into_the_next(): void
    {
        // Directly: fill the window with one chunk, then prove the NEXT chunk's
        // first address has to wait for it — the state is in shared storage, not
        // in a limiter instance the chunk owns.
        Mail::fake();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);
        $emails = $this->seedAddresses(11);
        $send = $this->warmupRun($site);

        $this->batch(array_slice($emails, 0, 10), $site, $send);

        $afterFirstChunk = Carbon::now();

        $this->batch(array_slice($emails, 10), $site, $send);

        $this->assertTrue(
            Carbon::now()->greaterThanOrEqualTo($afterFirstChunk->copy()->addSeconds(self::WINDOW)),
            'the eleventh address must wait for the first to leave the window',
        );
        $this->assertRollingLimit($this->attemptTimes());
    }

    // ── 3. Failures and the existing retry ───────────────────────────────────

    public function test_a_failed_send_still_consumes_its_slot(): void
    {
        // A refused recipient still opened a connection to the receiving host, so
        // it counts against the rate. Counting only successes would let a list of
        // dead addresses hammer the host at full speed.
        $this->useLocalTransport();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);
        $send = $this->warmupRun($site);

        $emails = [];
        for ($i = 1; $i <= 12; $i++) {
            $emails[] = "bad{$i}@example.com";
        }
        foreach ($emails as $email) {
            WarmupEmail::create(['email' => $email]);
        }

        $this->batch($emails, $site, $send);

        $this->assertSame(
            12,
            WarmupSendRecipient::query()->where('status', WarmupSendRecipient::STATUS_FAILED)->count(),
            'every attempt is recorded as failed',
        );
        $this->assertRollingLimit($this->attemptTimes());

        // Existing behaviour, unchanged by the limiter: a failure does not stamp
        // the cooldown, so the address stays eligible for the next run.
        $this->assertSame(0, WarmupEmail::query()->whereNotNull('last_sent_at')->count());
    }

    public function test_a_retry_of_the_same_batch_cannot_bypass_the_limit(): void
    {
        // The existing retry re-runs the WHOLE batch ($tries = 2). Because the
        // window lives outside the job instance, the second attempt is paced
        // against the first one's sends rather than starting from an empty window.
        $this->useLocalTransport();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);
        $emails = $this->seedAddresses(6);
        $send = $this->warmupRun($site);

        $job = new SendWarmupBatchJob($emails, $site->id, EmailTemplateCatalog::TYPE_PROMOTION, $send->id);

        // Attempt one, then the retry — the same payload, a new job instance, as
        // the queue would hand it back.
        $job->handle(app(WarmupMailResolver::class), app(WarmupRateLimiter::class));
        (new SendWarmupBatchJob($emails, $site->id, EmailTemplateCatalog::TYPE_PROMOTION, $send->id))
            ->handle(app(WarmupMailResolver::class), app(WarmupRateLimiter::class));

        $this->assertSame(12, WarmupSendRecipient::count(), 'both attempts are audited');
        $this->assertRollingLimit($this->attemptTimes());
    }

    // ── 4. Concurrency: the ceiling is global, not per worker ────────────────

    public function test_two_workers_sharing_the_store_share_one_ceiling(): void
    {
        // Two limiter instances — the situation two queue workers are in — reading
        // and writing one centralised window. `database` rather than `array`
        // because it is a real out-of-process store with real locks, so the only
        // thing the two instances have in common is the storage itself.
        config()->set('cache.default', 'database');
        Cache::store('database')->clear();

        $workerOne = new WarmupRateLimiter(Cache::store('database'));
        $workerTwo = new WarmupRateLimiter(Cache::store('database'));

        $granted = [];

        // Interleaved, as two workers draining the same queue would be.
        for ($i = 0; $i < 24; $i++) {
            $limiter = $i % 2 === 0 ? $workerOne : $workerTwo;
            $limiter->acquire();
            $granted[] = Carbon::now()->getTimestamp();
        }

        $this->assertRollingLimit($granted);
        $this->assertTrue($workerOne->isCentralised(), 'the database store is shared across processes');
    }

    public function test_an_in_memory_window_is_not_centralised(): void
    {
        // The honest negative: under CACHE_STORE=array each process gets its own
        // window, so the ceiling is per process. Documented rather than hidden,
        // because it is exactly the configuration a test suite runs under.
        $limiter = new WarmupRateLimiter(new Repository(new ArrayStore()));

        $this->assertFalse($limiter->isCentralised());

        $separateProcess = new WarmupRateLimiter(new Repository(new ArrayStore()));

        for ($i = 0; $i < self::MAX; $i++) {
            $limiter->acquire();
        }

        $at = Carbon::now();
        $separateProcess->acquire();

        $this->assertTrue(
            Carbon::now()->equalTo($at),
            'a second in-memory limiter does not see the first one\'s window — which is why production uses Redis',
        );
    }

    // ── 5. Restart semantics ─────────────────────────────────────────────────

    public function test_a_persistent_store_keeps_the_window_across_a_restart(): void
    {
        // Process restart, modelled as a brand-new limiter over the same storage:
        // a worker that dies mid-run must not resume with an empty window, or
        // `supervisorctl restart` would become a way to send at any rate.
        config()->set('cache.default', 'database');
        Cache::store('database')->clear();

        $before = new WarmupRateLimiter(Cache::store('database'));

        for ($i = 0; $i < self::MAX; $i++) {
            $before->acquire();
        }

        $restarted = new WarmupRateLimiter(Cache::store('database'));

        $this->assertSame(self::MAX, $restarted->used(), 'the window survived the restart');

        $at = Carbon::now();
        $restarted->acquire();

        $this->assertTrue(
            Carbon::now()->greaterThanOrEqualTo($at->copy()->addSeconds(self::WINDOW - 1)),
            'the first send after a restart still waits for the window to clear',
        );
    }

    public function test_the_window_expires_on_its_own(): void
    {
        // Nothing has to clean the key up: every entry is outside the window after
        // `window_seconds`, so an abandoned run leaves no lasting state.
        $limiter = app(WarmupRateLimiter::class);

        for ($i = 0; $i < self::MAX; $i++) {
            $limiter->acquire();
        }

        $this->assertSame(self::MAX, $limiter->used());

        Carbon::setTestNow(Carbon::now()->addSeconds(self::WINDOW + 1));

        $this->assertSame(0, $limiter->used(), 'the window empties as time passes, with no sweeper');
    }

    // ── 6. Configuration ─────────────────────────────────────────────────────

    public function test_the_limit_comes_from_configuration(): void
    {
        config()->set('warmup.rate_limit.max_emails', 3);
        config()->set('warmup.rate_limit.window_seconds', 30);

        $limiter = app(WarmupRateLimiter::class);

        $this->assertSame(3, $limiter->max());
        $this->assertSame(30, $limiter->windowSeconds());

        $granted = [];
        for ($i = 0; $i < 7; $i++) {
            $limiter->acquire();
            $granted[] = Carbon::now()->getTimestamp();
        }

        $this->assertRollingLimit($granted, 3, 30);
    }

    public function test_a_nonsensical_limit_falls_back_instead_of_disabling_the_ceiling(): void
    {
        // A typo in .env must not be a way to switch the protection off.
        config()->set('warmup.rate_limit.max_emails', 0);
        config()->set('warmup.rate_limit.window_seconds', -5);

        $limiter = app(WarmupRateLimiter::class);

        $this->assertSame(10, $limiter->max());
        $this->assertSame(60, $limiter->windowSeconds());
    }

    // ── 7. The batch job hands work back rather than being killed ────────────

    public function test_a_batch_too_slow_for_its_timeout_re_queues_the_remainder(): void
    {
        // 100 addresses at 10/min need ~10 minutes; send_timeout is 240s. Without
        // the hand-off the job would be killed mid-batch and retried, re-sending
        // everything it had already delivered. With it, every address is attempted
        // exactly once, in several invocations, still inside the rate limit.
        Mail::fake();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);
        $emails = $this->seedAddresses(100);
        $send = $this->warmupRun($site);

        // The continuation goes through the queue; `sync` runs it inline.
        $this->batch($emails, $site, $send);

        $this->assertSame(100, WarmupSendRecipient::count(), 'no address is skipped');
        $this->assertSame(
            100,
            WarmupSendRecipient::query()->distinct()->count('email'),
            'and none is attempted twice',
        );
        $this->assertRollingLimit($this->attemptTimes());
    }

    public function test_a_cancelled_run_stops_inside_the_batch(): void
    {
        // A batch now spans minutes, so "stop" has to land between two addresses
        // rather than only between two batches.
        Mail::fake();
        [$site] = $this->siteWithKey(['slug' => 'idevaffiliation']);
        $emails = $this->seedAddresses(30);
        $send = $this->warmupRun($site);

        Sleep::whenFakingSleep(function () use ($send): void {
            // Cancelled while the very first rate-limit wait is in progress.
            $send->forceFill(['cancelled_at' => Carbon::now()])->saveQuietly();
        });

        $this->batch($emails, $site, $send);

        $attempts = WarmupSendRecipient::count();

        $this->assertSame(self::MAX, $attempts, 'the first window went out, then the stop took effect');
        $this->assertRollingLimit($this->attemptTimes());
    }
}
