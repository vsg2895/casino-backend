<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Long-term promotion delivery history (see the create migration). Written in
 * bulk (one INSERT per campaign batch) and read by the admin history view. Its
 * UNIQUE (site_id, email, sent_date) also makes it the per-day idempotency
 * guard for the send flow — {@see sentTodayAmong()} + insertOrIgnore.
 */
class PromotionEmailHistory extends Model
{
    public $timestamps = false;

    protected $table = 'promotion_email_histories';

    protected $fillable = ['site_id', 'email', 'sent_date', 'created_at'];

    protected function casts(): array
    {
        return [
            'sent_date'  => 'date',
            'created_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Of the given candidate addresses, which already received today's promotion
     * for this site — one query, used to skip them before sending. Served by the
     * (site_id, sent_date) index + partition pruning to the current month.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    public static function sentTodayAmong(int $siteId, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return static::query()
            ->where('site_id', $siteId)
            ->where('sent_date', Carbon::today()->toDateString())
            ->whereIn('email', $emails)
            ->pluck('email')
            ->all();
    }

    /**
     * Bulk-insert one history row per delivered address in a SINGLE INSERT — not
     * one query per recipient. Called once per campaign batch with only the
     * addresses that were actually sent (failures are never passed in).
     *
     * insertOrIgnore + the UNIQUE (site_id, email, sent_date) make it idempotent:
     * a job retry or a concurrent worker can never write a duplicate for the same
     * email/day, so this doubles as the send flow's dedup guard.
     *
     * @param  list<string>  $emails
     */
    public static function recordMany(int $siteId, array $emails): void
    {
        if ($emails === []) {
            return;
        }

        $now = Carbon::now();
        $sentDate = $now->toDateString();

        $rows = [];
        foreach ($emails as $email) {
            $rows[] = [
                'site_id'    => $siteId,
                'email'      => $email,
                'sent_date'  => $sentDate,
                'created_at' => $now,
            ];
        }

        static::query()->insertOrIgnore($rows);
    }
}
