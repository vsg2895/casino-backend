<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One warmup delivery attempt — which address was mailed, from which site, with
 * which template, and when.
 *
 * The per-recipient counterpart of {@see WarmupSend}: that row says a run
 * happened, this one says what each address actually received. A batch never
 * aborts on one bad address, so this table is the only place a partial failure
 * is visible afterwards.
 *
 * `email` is stored as a plain string alongside the nullable `warmup_email_id`:
 * the history records what was sent and must outlive the address being removed
 * from the list. Same choice, for the same reason, as {@see PhoneSmsHistory}
 * storing a number and {@see PromotionEmailHistory} storing an address.
 */
class WarmupSendRecipient extends Model
{
    public const string STATUS_SENT = 'sent';
    public const string STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const array STATUSES = [self::STATUS_SENT, self::STATUS_FAILED];

    protected $fillable = [
        'warmup_send_id',
        'warmup_email_id',
        'site_id',
        'email',
        'template',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'warmup_send_id'  => 'integer',
            'warmup_email_id' => 'integer',
            'site_id'         => 'integer',
            'sent_at'         => 'datetime',
        ];
    }

    public function warmupSend(): BelongsTo
    {
        return $this->belongsTo(WarmupSend::class);
    }

    public function warmupEmail(): BelongsTo
    {
        return $this->belongsTo(WarmupEmail::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Case-insensitive prefix search on the address.
     *
     * Matches {@see WarmupEmail::scopeSearch()} exactly, so the same input
     * behaves the same way in the list and in the history.
     *
     * Every filter scope below takes `mixed` rather than a narrow type on
     * purpose: these are fed straight from the query string, and `?site_id[]=1`
     * hands a PHP array to a `?string` parameter, which is a TypeError — a 500
     * from a URL anyone can type. Non-scalars are ignored instead.
     */
    public function scopeSearch(Builder $query, mixed $term): Builder
    {
        $term = is_scalar($term) ? trim((string) $term) : '';

        if ($term === '') {
            return $query;
        }

        // Escape LIKE wildcards so user input cannot turn into a %..% scan.
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

        return $query->where('email', 'like', $escaped . '%');
    }

    /** Narrow to one site, one template or one outcome — the history filters. */
    public function scopeForSite(Builder $query, mixed $siteId): Builder
    {
        if (! is_scalar($siteId) || trim((string) $siteId) === '') {
            return $query;
        }

        return $query->where('site_id', (int) $siteId);
    }

    public function scopeForTemplate(Builder $query, mixed $template): Builder
    {
        if (! is_string($template) || $template === '') {
            return $query;
        }

        return $query->where('template', $template);
    }

    public function scopeWithStatus(Builder $query, mixed $status): Builder
    {
        return in_array($status, self::STATUSES, true) ? $query->where('status', $status) : $query;
    }

    /**
     * Newest first, with `id` as the tiebreaker.
     *
     * Not cosmetic: one batch writes up to 100 rows sharing a single `sent_at`,
     * and MySQL gives no stable order among ties — without the tiebreaker, paging
     * repeats some rows and skips others. Served by `warmup_recipients_sent_index`.
     */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderByDesc('sent_at')->orderByDesc('id');
    }
}
