<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The factual profile of one casino.
 *
 * Global master data, like the casino itself: a licence and a withdrawal time
 * are facts about the operator, not opinions this network holds, so they are
 * identical on every site that renders them. Uniqueness between our domains
 * comes from the wrapper — per-site copy, byline and meta — never from varying
 * the facts, which would give six different answers to the same factual question.
 *
 * Every attribute is nullable and the public page renders a group only when it
 * has values. That is the load-bearing rule for this feature: a detail table of
 * mostly-blank rows reads worse than no table at all.
 */
class CasinoDetail extends Model
{
    /** @var list<string> */
    public const array TOOLS = [
        'tool_deposit_limit',
        'tool_loss_limit',
        'tool_session_limit',
        'tool_reality_check',
        'tool_withdrawal_lock',
        'tool_self_exclusion',
    ];

    /** @var list<string> */
    public const array LIST_FIELDS = [
        'licences',
        'currencies',
        'payment_methods',
        'game_providers',
        'support_languages',
    ];

    protected $fillable = [
        'established_year',
        'company',
        'licences',
        'currencies',
        'payment_methods',
        'min_deposit',
        'min_withdrawal',
        'withdrawal_limit',
        'pending_time',
        'withdrawal_time',
        'verification_speed',
        'deposit_fees',
        'withdrawal_fees',
        'game_providers',
        'rng_tested',
        'progressive_jackpots',
        'live_chat',
        'email_support',
        'support_email',
        'support_languages',
        ...self::TOOLS,
    ];

    protected function casts(): array
    {
        return [
            'established_year'     => 'integer',
            'licences'             => 'array',
            'currencies'           => 'array',
            'payment_methods'      => 'array',
            'game_providers'       => 'array',
            'support_languages'    => 'array',
            // Nullable booleans throughout. NULL means "we have not checked",
            // which is a different claim from FALSE ("the operator does not
            // offer this") — and on the safer-play tools that distinction is the
            // difference between an unknown and an accusation.
            'deposit_fees'         => 'boolean',
            'withdrawal_fees'      => 'boolean',
            'rng_tested'           => 'boolean',
            'progressive_jackpots' => 'boolean',
            'live_chat'            => 'boolean',
            'email_support'        => 'boolean',
            'tool_deposit_limit'   => 'boolean',
            'tool_loss_limit'      => 'boolean',
            'tool_session_limit'   => 'boolean',
            'tool_reality_check'   => 'boolean',
            'tool_withdrawal_lock' => 'boolean',
            'tool_self_exclusion'  => 'boolean',
        ];
    }

    public function casino(): BelongsTo
    {
        return $this->belongsTo(Casino::class);
    }

    /**
     * True when the profile carries nothing worth rendering.
     *
     * Used by the public resource so an empty companion row — created the moment
     * an admin opens the tab and saves without typing anything — is reported as
     * absent rather than shipping an empty object the front end then has to
     * second-guess.
     */
    public function isEmpty(): bool
    {
        foreach (array_keys($this->getAttributes()) as $key) {
            if (in_array($key, ['id', 'casino_id', 'created_at', 'updated_at'], true)) {
                continue;
            }

            $value = $this->{$key};

            if (is_array($value) ? $value !== [] : $value !== null) {
                return false;
            }
        }

        return true;
    }
}
