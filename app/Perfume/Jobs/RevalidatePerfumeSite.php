<?php

declare(strict_types=1);

namespace App\Perfume\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ask the storefront to regenerate after an admin edit: POST
 * {perfume.site_url}/api/revalidate with the shared secret and the tags.
 *
 * Like the platform's RevalidateNextJsSites, a storefront that is down
 * must not fail an admin save, so HTTP errors are logged, never thrown.
 * `high` queue: an admin is waiting to see the change.
 */
class RevalidatePerfumeSite implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const string ON_QUEUE = 'high';

    public int $timeout = 30;

    public int $tries = 1;

    /** @param list<string> $tags */
    public function __construct(public readonly array $tags = ['perfume'])
    {
        $this->onQueue(self::ON_QUEUE);
    }

    public function handle(): void
    {
        $secret = (string) config('perfume.revalidate_secret', '');
        $url = (string) config('perfume.site_url', '');

        if ($secret === '' || $url === '') {
            return;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['x-revalidate-secret' => $secret])
                ->post($url . '/api/revalidate', ['tags' => $this->tags]);

            if (! $response->successful()) {
                Log::warning('Perfume revalidation failed', ['status' => $response->status(), 'tags' => $this->tags]);
            }
        } catch (\Throwable $e) {
            Log::warning('Perfume revalidation unreachable', ['error' => $e->getMessage()]);
        }
    }
}
