<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessNewsletterSubscription;
use App\Jobs\SendNewsletterWelcomeEmail;
use App\Jobs\SendRegistrationPromotionJob;
use App\Jobs\SendVerificationPromotionJob;
use Tests\TestCase;

/**
 * Pins the explicit timeouts on the jobs that used to inherit the worker's.
 *
 * Measured against the REDIS connection's retry_after, which production runs on.
 * The suite's default connection is `sync`, which has no retry_after at all, so
 * reading `queue.default` here would compare against a fallback.
 */
class JobTimeoutTest extends TestCase
{
    public function test_single_send_jobs_declare_a_timeout_under_the_redis_retry_after(): void
    {
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $jobs = [
            new ProcessNewsletterSubscription(1, 'someone@example.test'),
            new SendNewsletterWelcomeEmail(1, 'someone@example.test'),
            new SendVerificationPromotionJob(1),
            new SendRegistrationPromotionJob(1),
        ];

        foreach ($jobs as $job) {
            // 60 is the worker default these jobs already ran under in production.
            $this->assertSame(60, $job->timeout, $job::class);
            $this->assertLessThan($retryAfter, $job->timeout, $job::class);
        }
    }
}
