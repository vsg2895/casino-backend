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

    /**
     * Every column EXCEPT the two message bodies.
     *
     * `html_body` and `plaintext_body` are LONGTEXT holding the full rendered
     * campaign — tens to hundreds of kilobytes each — and no API response ever
     * returns them: {@see \App\Http\Resources\UniOne\UniOneSendResource} has
     * no field for either, and only the send path reads them. A `SELECT *`
     * listing therefore dragged ~25 copies of two large blobs out of MySQL,
     * over the wire and into PHP memory on every page of the send log, to throw
     * all of it away.
     *
     * Kept as an explicit list rather than a schema diff so the cost of a
     * column is visible where the column is added; SendColumnTrimTest fails if
     * this drifts from the table, so a new column cannot go missing silently.
     */
    public const array LIST_COLUMNS = [
        'id',
        'unione_api_key_id',
        'user_id',
        'subject',
        'from_email',
        'from_name',
        'reply_to',
        'requested_count',
        'cooldown_days',
        'eligible_count',
        'accepted_count',
        'failed_count',
        'chunk_count',
        'status',
        'error',
        'completed_at',
        'created_at',
        'updated_at',
    ];

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
