<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CasinoDetail;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CasinoDetail
 *
 * Serves BOTH the admin form and the public casino page, deliberately: the
 * editor must see exactly the values the visitor will, or the two drift and
 * "why isn't my change showing" becomes unanswerable.
 *
 * List fields are normalised to real arrays. A JSON column that has never been
 * written comes back NULL, and a front end that has to handle `null | []` for
 * the same "nothing here" state will eventually get it wrong in one of the two.
 */
class CasinoDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payload = [
            'established_year'     => $this->established_year,
            'company'              => $this->company,
            'licences'             => $this->list('licences'),
            'currencies'           => $this->list('currencies'),
            'payment_methods'      => $this->list('payment_methods'),
            'min_deposit'          => $this->min_deposit,
            'min_withdrawal'       => $this->min_withdrawal,
            'withdrawal_limit'     => $this->withdrawal_limit,
            'pending_time'         => $this->pending_time,
            'withdrawal_time'      => $this->withdrawal_time,
            'verification_speed'   => $this->verification_speed,
            'deposit_fees'         => $this->deposit_fees,
            'withdrawal_fees'      => $this->withdrawal_fees,
            'game_providers'       => $this->list('game_providers'),
            'rng_tested'           => $this->rng_tested,
            'progressive_jackpots' => $this->progressive_jackpots,
            'live_chat'            => $this->live_chat,
            'email_support'        => $this->email_support,
            'support_email'        => $this->support_email,
            'support_languages'    => $this->list('support_languages'),
        ];

        foreach (CasinoDetail::TOOLS as $tool) {
            $payload[$tool] = $this->{$tool};
        }

        return $payload;
    }

    /** @return list<string> */
    private function list(string $field): array
    {
        $value = $this->{$field};

        return is_array($value) ? array_values($value) : [];
    }
}
