<?php

declare(strict_types=1);

namespace App\Models\UniOne;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One send run. */
class UniOneSend extends Model
{
    protected $table = 'unione_sends';

    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_SENDING = 'sending';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_CANCELLED = 'cancelled';

    /** UniOne's hard cap: 500 recipients per request. */
    public const int CHUNK_SIZE = 500;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function key(): BelongsTo
    {
        return $this->belongsTo(UniOneApiKey::class, 'unione_api_key_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(UniOneSendChunk::class, 'unione_send_id')->orderBy('chunk_index');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(UniOneSendRecipient::class, 'unione_send_id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $term === '' ? $query : $query->where('subject', 'like', "%{$term}%");
    }
}
