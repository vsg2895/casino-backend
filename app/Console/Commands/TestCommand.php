<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('test:command')]
#[Description('For Live Check')]
class TestCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info('Command Work every minute ' . \Illuminate\Support\now()->format('Y-m-d H:i:s'));
        $this->info('Command Work every minute ' . \Illuminate\Support\now()->format('Y-m-d H:i:s'));
    }
}
