<?php

declare(strict_types=1);

namespace App\Models\UniOne;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One address on the UniOne list.
 *
 * Unrelated to `newsletters` — no foreign key, no shared read, no shared write.
 *
 * ── scopeSendable() is the ONLY way a send path selects rows ────────────────
 *
 * The brief requires consent enforcement "in the model scope, not in the
 * caller", and this is why: a caller that forgets is a caller that mails an
 * unconsented address, and UniOne closes accounts for that. Putting the rule in
 * one scope means there is exactly one thing to get right, and every send path
 * goes through it.
 */
class UniOneReceiver extends Model
{
    use SoftDeletes;

    protected $table = 'unione_receivers';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const string STATUS_BOUNCED = 'bounced';

    public const string STATUS_COMPLAINED = 'complained';

    public const string STATUS_SUPPRESSED = 'suppressed';

    /** @var list<string> */
    public const array STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_UNSUBSCRIBED,
        self::STATUS_BOUNCED,
        self::STATUS_COMPLAINED,
        self::STATUS_SUPPRESSED,
    ];

    /**
     * How long a `temporary_unavailable` address sits out.
     *
     * The brief's three days. A constant rather than config because changing it
     * changes deliverability behaviour, not an environment preference.
     */
    public const int TEMPORARY_FAILURE_DAYS = 3;

    /**
     * UniOne `failed_emails` reason → what it means for this row.
     *
     * The single mapping table the brief asks for, used by BOTH the synchronous
     * `failed_emails` path and the asynchronous webhook path so the two can
     * never disagree about what "blocked" means.
     *
     * `duplicate` is absent on purpose: it says nothing about the address, only
     * that it appeared twice in one request. It is handled by de-duplicating
     * before the request is built.
     *
     * @var array<string, string>
     */
    public const array FAILURE_STATUS_MAP = [
        'invalid'               => self::STATUS_BOUNCED,
        'permanent_unavailable' => self::STATUS_BOUNCED,
        'unsubscribed'          => self::STATUS_UNSUBSCRIBED,
        'complained'            => self::STATUS_COMPLAINED,
        'blocked'               => self::STATUS_UNSUBSCRIBED,
        // temporary_unavailable is NOT here — it keeps `active` and gets a
        // retry_after instead. See applyFailure().
    ];

    protected $fillable = [
        'email',
        'name',
        'status',
        'consent_source',
        'consent_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'consent_at'      => 'datetime',
            'last_sent_at'    => 'datetime',
            'retry_after'     => 'datetime',
            'send_count'      => 'integer',
            'bounce_count'    => 'integer',
            'complaint_count' => 'integer',
        ];
    }

    /**
     * Eligible to receive mail.
     *
     * Two conditions:
     *   - status active — anything else bounced, was suppressed or opted out
     *   - not inside a temporary-failure hold
     *
     * Every address on the list is sendable when it is added: an import lands
     * as `active`, and only UniOne's own webhooks (unsubscribe, bounce,
     * complaint) or a temporary-failure hold move it out of this set. There is
     * no separate "sendable" flag to maintain, and the admin no longer shows
     * one — the status column is the whole story.
     *
     * ── Consent is NO LONGER checked here ───────────────────────────────────
     *
     * It used to be, and the columns still exist. The check was removed at the
     * operator's request so the import could take a file and nothing else.
     *
     * The consequence is worth stating where the decision lives: UniOne's terms
     * require documented consent, and this scope is no longer the thing that
     * guarantees it. `consent_source` and `consent_at` are now optional metadata
     * — still editable per receiver, still exported, but not enforced. If the
     * account is ever reviewed, the evidence has to come from the operator's own
     * records rather than from here.
     *
     * Served by `unione_receivers_batch_idx (status, last_sent_at)`.
     */
    public function scopeSendable(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $q): void {
                $q->whereNull('retry_after')->orWhere('retry_after', '<=', now());
            });
    }

    /**
     * Exclude anyone contacted inside the cooldown.
     *
     * The NULL branch is explicit: `last_sent_at > cutoff` is UNKNOWN for a NULL
     * in MySQL, so a never-contacted address would silently drop out of the very
     * set it should lead.
     */
    public function scopeOutsideCooldown(Builder $query, ?int $days): Builder
    {
        if ($days === null || $days < 1) {
            return $query;
        }

        // Days, not hours — the same unit and the same rule as Warmup's
        // `notContactedWithin()`: "2" skips anyone contacted in the last two days.
        $cutoff = now()->subDays($days);

        return $query->where(function (Builder $q) use ($cutoff): void {
            $q->whereNull('last_sent_at')->orWhere('last_sent_at', '<=', $cutoff);
        });
    }

    /**
     * Rotation order: never-contacted first, then least recently contacted.
     *
     * MySQL sorts NULLs first ascending, which is exactly the rule — so no
     * NULLS FIRST emulation is needed, and the ordering comes straight out of
     * `unione_receivers_batch_idx` with no filesort.
     *
     * `id` closes the sort so a page boundary between two rows sharing a
     * `last_sent_at` — the norm right after a send stamps a whole batch — is
     * deterministic.
     */
    public function scopeRotation(Builder $query): Builder
    {
        // NO `last_sent_at IS NOT NULL` expression here, tempting as it is:
        // MySQL already sorts NULLs first ascending, and adding the expression
        // would put a COMPUTED column in the ORDER BY, which the index cannot
        // serve — reintroducing the filesort this index exists to remove.
        return $query->orderBy('last_sent_at')->orderBy('id');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('email', 'like', "%{$term}%")->orWhere('name', 'like', "%{$term}%");
        });
    }

    /** Whether consent is on file. Both halves required. */
    public function hasConsent(): bool
    {
        return $this->consent_at !== null && trim((string) $this->consent_source) !== '';
    }
}
