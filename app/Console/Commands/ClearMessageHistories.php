<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Empties a message history completely — every row, not a retention window.
 *
 *   php artisan histories:clear --type=sms
 *   php artisan histories:clear --type=all --force
 *   php artisan histories:clear --type=unione --dry-run
 *
 * The companion to {@see PruneMessageHistories}, and deliberately a SEPARATE
 * command rather than `--months=0`. The prune runs nightly and takes the tail;
 * this takes everything and is only ever typed by a person who means it — after
 * a test campaign, before handing a database over, when a log has served its
 * purpose. Two different intentions should not share one invocation where a
 * missing flag turns the gentle one into the other.
 *
 * It asks for confirmation in production (ConfirmableTrait, `--force` skips),
 * and `--dry-run` reports the count without touching anything.
 *
 * ── What "cleared" means per stream ─────────────────────────────────────────
 *
 * The same six streams the prune covers, by the same parents, so the cascades
 * behave identically: clearing `warmup` removes the runs and their recipient
 * rows, clearing `unione` removes the runs with their chunks and recipients.
 *
 * Chunked DELETE rather than TRUNCATE: three of these tables are the parent
 * side of a foreign key, where TRUNCATE is refused outright, and a DELETE is
 * what makes the cascades fire at all.
 */
class ClearMessageHistories extends Command
{
    use ConfirmableTrait;

    protected $signature = 'histories:clear
                            {--type= : Stream to clear, or "all" (required)}
                            {--dry-run : Report what would be deleted, delete nothing}
                            {--force : Skip the production confirmation}';

    protected $description = 'Delete EVERY row of one message history (or all of them)';

    private const int CHUNK = 1000;

    /**
     * stream key => [table, what a row is]
     *
     * The same set as the retention prune, named the same way, so one mental
     * model covers both commands.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array STREAMS = [
        'promotion'         => ['promotion_email_histories', 'promotion send'],
        'post-verification' => ['verification_promotion_sends', 'after-verification send'],
        'verify'            => ['verify_email_sends', 'verify-email send'],
        'warmup'            => ['warmup_sends', 'warmup run (with its recipients)'],
        'sms'               => ['phone_sms_histories', 'SMS attempt'],
        'unione'            => ['unione_sends', 'UniOne run (with its chunks and recipients)'],
        'unione-events'     => ['unione_webhook_events', 'UniOne delivery event'],
    ];

    public function handle(): int
    {
        $type = trim((string) $this->option('type'));

        if ($type === '') {
            $this->error('--type is required. Pass one of: ' . implode(', ', array_keys(self::STREAMS)) . ', all');

            return self::FAILURE;
        }

        $streams = $type === 'all' ? array_keys(self::STREAMS) : [$type];
        $unknown = array_diff($streams, array_keys(self::STREAMS));

        if ($unknown !== []) {
            $this->error('Unknown stream: ' . implode(', ', $unknown));
            $this->line('Available: ' . implode(', ', array_keys(self::STREAMS)) . ', all');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        // Counted BEFORE the confirmation, so the person approving it is told
        // how much they are approving.
        $counts = [];
        foreach ($streams as $key) {
            [$table] = self::STREAMS[$key];
            $counts[$key] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        $total = array_sum($counts);

        $this->table(
            ['Stream', 'Table', $dryRun ? 'Would delete' : 'To delete'],
            array_map(
                fn (string $key): array => [$key, self::STREAMS[$key][0], (string) $counts[$key]],
                $streams,
            ),
        );

        if ($total === 0) {
            $this->info('Nothing to clear.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment("Dry run — {$total} row(s) would be deleted. Nothing was touched.");

            return self::SUCCESS;
        }

        // In production this prompts; --force is the deliberate override. The
        // trait is Laravel's own, so the behaviour matches `migrate` and
        // `db:wipe` rather than being a second convention to learn.
        if (! $this->confirmToProceed('This deletes ALL rows of: ' . implode(', ', $streams))) {
            return self::FAILURE;
        }

        $deleted = 0;

        foreach ($streams as $key) {
            [$table, $unit] = self::STREAMS[$key];

            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = $this->deleteInChunks($table);
            $deleted += $rows;
            $this->line(sprintf('  %-18s %7d %s(s)', $key, $rows, $unit));
        }

        $this->info("Cleared {$deleted} row(s).");

        return self::SUCCESS;
    }

    /** Chunked so the delete never holds a lock for longer than a thousand rows. */
    private function deleteInChunks(string $table): int
    {
        $deleted = 0;

        do {
            $batch = DB::table($table)->limit(self::CHUNK)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        return $deleted;
    }
}
