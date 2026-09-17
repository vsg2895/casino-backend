<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\UniOne\UniOneReceiver;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 100,000 receivers, for the batch-selection EXPLAIN the brief asks for.
 *
 * Production-guarded and idempotent-ish: it tops the table up to the target
 * rather than duplicating, so re-running is cheap and never doubles the list.
 *
 *   php artisan db:seed --class=UniOneVolumeSeeder
 */
class UniOneVolumeSeeder extends Seeder
{
    private const TARGET = 100_000;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Refusing to run in production.');

            return;
        }

        $existing = DB::table('unione_receivers')->count();

        if ($existing >= self::TARGET) {
            $this->command?->info("Already {$existing} receivers — nothing to do.");

            return;
        }

        $now = now();
        $rows = [];
        $written = 0;

        for ($i = $existing + 1; $i <= self::TARGET; $i++) {
            /*
             * A realistic distribution, because a uniform one would flatter the
             * index. Roughly:
             *   30% never contacted  (last_sent_at NULL — these lead the rotation)
             *   60% contacted at spread-out times
             *   10% not active, so the status filter has real work to do
             */
            $never = $i % 10 < 3;
            $status = match (true) {
                $i % 50 === 0 => UniOneReceiver::STATUS_BOUNCED,
                $i % 77 === 0 => UniOneReceiver::STATUS_UNSUBSCRIBED,
                $i % 91 === 0 => UniOneReceiver::STATUS_COMPLAINED,
                default       => UniOneReceiver::STATUS_ACTIVE,
            };

            $rows[] = [
                'email'          => "sample.receiver.{$i}@example.test",
                'name'           => "Sample Receiver {$i}",
                'status'         => $status,
                'consent_source' => 'seeded:volume-test',
                'consent_at'     => $now,
                'last_sent_at'   => $never ? null : (clone $now)->subMinutes(random_int(1, 400_000)),
                'send_count'     => $never ? 0 : random_int(1, 20),
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            if (count($rows) >= 2_000) {
                DB::table('unione_receivers')->insert($rows);
                $written += count($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('unione_receivers')->insert($rows);
            $written += count($rows);
        }

        $this->command?->info("Seeded {$written} receivers (total " . DB::table('unione_receivers')->count() . ').');
    }
}
