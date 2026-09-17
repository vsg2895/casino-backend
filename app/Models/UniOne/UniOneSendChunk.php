<?php

declare(strict_types=1);

namespace App\Models\UniOne;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One API call's worth of recipients.
 *
 * `committed_at` is the duplicate guard — see the migration. It is set in the
 * same transaction that stamps this chunk's recipients, so a retry that finds it
 * returns without calling UniOne again.
 */
class UniOneSendChunk extends Model
{
    protected $table = 'unione_send_chunks';

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_COMMITTED = 'committed';
    public const string STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['committed_at' => 'datetime'];
    }

    public function send(): BelongsTo
    {
        return $this->belongsTo(UniOneSend::class, 'unione_send_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(UniOneSendRecipient::class, 'unione_send_chunk_id');
    }

    /**
     * The deterministic idempotence key for (send, chunk).
     *
     * Derived, never random: a retry must produce the SAME string or the key
     * does nothing at all. Capped at the spec's 64 characters.
     *
     * Worth being honest about what this buys: UniOne honours it for one minute,
     * and this queue's retry_after is 1200s, so a normal retry arrives long after
     * the key has expired. It defends against a fast double-dispatch only —
     * `committed_at` is what defends against everything else.
     */
    public static function idempotenceKeyFor(int $sendId, int $chunkIndex): string
    {
        return substr("unione-s{$sendId}-c{$chunkIndex}-" . hash('xxh128', "unione:{$sendId}:{$chunkIndex}"), 0, 64);
    }

    public function isCommitted(): bool
    {
        return $this->committed_at !== null;
    }
}
