<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Contracts\TokenAddressable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The opt-out credential every row on a global list carries, and the links
 * built from it. Implements {@see TokenAddressable}.
 *
 * Lifted out of {@see \App\Models\CampaignReceiver}, where this logic already
 * lived and was correct, so {@see WarmupEmail} could have exactly the same
 * behaviour rather than a second version of it that drifts. The receiver lists
 * behave as they did before; warmup is the one that gains an opt-out.
 */
trait HasUnsubscribeToken
{
    /**
     * Generated on `creating` rather than in a controller so EVERY path —
     * manual form, spreadsheet import, factory, tinker, seeder — produces a
     * token. A row without one can be mailed but never unsubscribed from, which
     * is the one state that must not exist.
     *
     * Bulk `insert()`/`insertOrIgnore()` bypasses model events, so the import
     * services set the column themselves; this is their safety net, not their
     * mechanism.
     */
    public static function bootHasUnsubscribeToken(): void
    {
        static::creating(function (Model $row): void {
            if ((string) $row->unsubscribe_token === '') {
                $row->unsubscribe_token = self::newToken();
            }
        });
    }

    /** 64 hex chars — matches the newsletter token width and column length. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function unsubscribeToken(): string
    {
        return (string) $this->unsubscribe_token;
    }

    /**
     * The token, minting and persisting one if this row somehow has none.
     *
     * The safety net for the window between deploying the warmup token column
     * and running {@see \Database\Seeders\ReceiverUnsubscribeTokenSeeder}: in
     * it, an un-backfilled row would otherwise be mailed a message whose
     * unsubscribe link ends in nothing. Called by the send path, so it costs a
     * write only for a row that is about to be mailed and has no token — none,
     * once the backfill has run.
     */
    public function ensureUnsubscribeToken(): string
    {
        if ((string) $this->unsubscribe_token === '') {
            $this->forceFill(['unsubscribe_token' => self::newToken()])->save();
        }

        return (string) $this->unsubscribe_token;
    }

    /** Absolute, token-addressed, and unguessable — see {@see newToken()}. */
    public function unsubscribeUrl(): string
    {
        return $this->apiUrl(static::unsubscribePath());
    }

    /**
     * The same URL as the in-body link: the route answers POST (acts, for RFC
     * 8058) and GET (renders a confirm page, because scanners prefetch GETs).
     */
    public function oneClickUrl(): string
    {
        return $this->unsubscribeUrl();
    }

    /** Overridden by a list that stores no name. */
    public function recipientName(): ?string
    {
        return $this->name;
    }

    /** Rows whose token has not been generated yet — what the backfill fills. */
    public function scopeMissingUnsubscribeToken(Builder $query): Builder
    {
        return $query->where(static function (Builder $q): void {
            $q->whereNull('unsubscribe_token')->orWhere('unsubscribe_token', '');
        });
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/v1/' . $path . '/' . $this->unsubscribe_token;
    }
}
