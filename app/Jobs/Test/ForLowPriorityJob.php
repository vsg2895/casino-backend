<?php

namespace App\Jobs\Test;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ForLowPriorityJob implements ShouldQueue
{
    use Queueable;

    public const string ON_QUEUE = 'low';
    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue(self::ON_QUEUE);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Low Priority Job Started');
    }
}
