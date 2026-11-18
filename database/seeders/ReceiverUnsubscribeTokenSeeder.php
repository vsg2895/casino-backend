<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Contracts\TokenAddressable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Gives every address on a global list an unsubscribe token: Mailgun
 * receivers, SendGrid receivers and the warmup list.
 *
 * Needed because a token is minted by the model's `creating` hook, and two
 * kinds of row never passed through it:
 *
 *  - every warmup address, for which the column did not exist until the
 *    2026_12_05 migration;
 *  - any receiver written by a bulk `insertOrIgnore`, which fires no model
 *    events. The import services set the column inline for exactly this
 *    reason, so these should be none — this seeder proves it rather than
 *    assuming it.
 *
 * A row with no token can be mailed but never unsubscribed from, and since
 * 2026-12 it also cannot receive the subscription or verify template, whose
 * links are built from that token. So this is not cosmetic: run it immediately
 * after migrating, BEFORE the next scheduled send.
 *
 *   php artisan db:seed --class=ReceiverUnsubscribeTokenSeeder --force
 *
 * Production-safe:
 *  - it only ever fills a token that is NULL or empty. No other column is
 *    read or written, and a row that already has one is not touched — so a
 *    second run reports zero and changes nothing;
 *  - each token is 64 random hex chars from {@see random_bytes()}, the same
 *    generator the models use, and is written per row: no two rows can share
 *    a credential;
 *  - it works in chunks inside a transaction per chunk, so a very large list
 *    neither loads into memory nor holds one long lock;
 *  - soft-deleted receivers are included. They are invisible today but can be
 *    restored, and a restored row with no token is the same defect again.
 *
 * Not part of DatabaseSeeder: that file is the local bootstrap, and this is a
 * one-off repair of existing production data.
 */
class ReceiverUnsubscribeTokenSeeder extends Seeder
{
    /** Rows per transaction. Small enough to keep locks short on a big list. */
    private const int CHUNK = 500;

    public function run(): void
    {
        $total = 0;

        foreach (TokenAddressable::LISTS as $model) {
            $filled = $this->backfill($model);
            $total += $filled;

            $this->command?->info(sprintf(
                '  %-20s %s',
                (new $model())->getTable(),
                $filled === 0 ? 'every row already had a token' : "{$filled} token(s) written",
            ));
        }

        $this->command?->info($total === 0
            ? 'Nothing to do — every address on every list already has an unsubscribe token.'
            : "Done. {$total} address(es) can now unsubscribe, and can be sent the subscription and verify templates.");
    }

    /**
     * Fill one list's missing tokens, in chunks.
     *
     * The loop re-queries instead of paginating on purpose: writing the token
     * removes the row from the filter, so every iteration's "first CHUNK rows
     * still missing one" is the next batch. An OFFSET would step over rows it
     * had just fixed.
     *
     * @param  class-string<TokenAddressable>  $model
     */
    private function backfill(string $model): int
    {
        $filled = 0;
        $table = (new $model())->getTable();

        while (true) {
            $ids = $this->missing($model)->limit(self::CHUNK)->pluck('id');

            if ($ids->isEmpty()) {
                return $filled;
            }

            DB::transaction(static function () use ($ids, $table, $model, &$filled): void {
                foreach ($ids as $id) {
                    // Per row, because each needs its OWN token — a single
                    // UPDATE would give the whole chunk one value and collide
                    // on the unique index.
                    $filled += DB::table($table)
                        ->where('id', $id)
                        ->update(['unsubscribe_token' => $model::newToken()]);
                }
            });
        }
    }

    /**
     * Rows still missing a token, including soft-deleted ones where the model
     * has that trait.
     *
     * @param  class-string<TokenAddressable>  $model
     * @return Builder<Model>
     */
    private function missing(string $model): Builder
    {
        $query = $model::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        return $query->missingUnsubscribeToken()->orderBy('id');
    }
}
