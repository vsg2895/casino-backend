<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One day's click count on one outbound link.
 *
 * See the migration for why this is a daily aggregate and why
 * `special_offer_id` is 0 rather than NULL for a casino-level click.
 */
class AffiliateClick extends Model
{
    /** `special_offer_id` value meaning "the casino's own link". */
    public const int NO_OFFER = 0;

    protected $fillable = ['site_id', 'casino_id', 'special_offer_id', 'clicked_on', 'count'];

    protected function casts(): array
    {
        return ['clicked_on' => 'date', 'count' => 'integer'];
    }

    public function casino(): BelongsTo
    {
        return $this->belongsTo(Casino::class);
    }

    /**
     * Increment today's count, creating the row if this is the day's first click.
     *
     * An atomic upsert rather than find-then-save: two clicks arriving in the
     * same millisecond would otherwise both read 4, both write 5, and one click
     * would vanish. `ON DUPLICATE KEY UPDATE` makes the database do the addition.
     */
    public static function record(int $siteId, int $casinoId, int $offerId = self::NO_OFFER): void
    {
        $now = now();

        DB::table('affiliate_clicks')->upsert(
            [[
                'site_id'          => $siteId,
                'casino_id'        => $casinoId,
                'special_offer_id' => $offerId,
                'clicked_on'       => $now->toDateString(),
                'count'            => 1,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]],
            ['site_id', 'casino_id', 'special_offer_id', 'clicked_on'],
            // Raw increment: passing ['count' => 1] would RESET the row to 1 on
            // every click after the first.
            ['count' => DB::raw('affiliate_clicks.count + 1'), 'updated_at' => $now],
        );
    }
}
