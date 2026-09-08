<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\CasinoDetail;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The casino's factual profile.
 *
 * Everything is `nullable`. A half-known operator is the normal case — an editor
 * fills in what the licence register and the operator's own pages actually state
 * and leaves the rest blank, and the public page renders only what has a value.
 * Requiring any field here would push editors toward inventing one.
 *
 * The booleans are `nullable` too, and that is deliberate: NULL means "not
 * checked", FALSE means "the operator does not offer this". On the safer-play
 * tools that is the difference between an unknown and an accusation, so the two
 * must not collapse into one value.
 */
class UpdateCasinoDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $list = ['nullable', 'array', 'max:40'];
        $listItem = ['string', 'max:80'];
        $shortText = ['nullable', 'string', 'max:120'];
        $tristate = ['nullable', 'boolean'];

        $rules = [
            // Bounded to something a real casino could claim. 1990 predates
            // online casinos; a year in the future is a typo.
            'established_year'   => ['nullable', 'integer', 'min:1990', 'max:' . (int) date('Y')],
            'company'            => ['nullable', 'string', 'max:255'],
            'licences'           => $list,
            'licences.*'         => $listItem,
            'currencies'         => $list,
            'currencies.*'       => $listItem,
            'payment_methods'    => $list,
            'payment_methods.*'  => $listItem,
            'min_deposit'        => $shortText,
            'min_withdrawal'     => $shortText,
            'withdrawal_limit'   => $shortText,
            'pending_time'       => $shortText,
            'withdrawal_time'    => $shortText,
            'verification_speed' => $shortText,
            'deposit_fees'       => $tristate,
            'withdrawal_fees'    => $tristate,
            'game_providers'     => $list,
            'game_providers.*'   => $listItem,
            'rng_tested'         => $tristate,
            'progressive_jackpots' => $tristate,
            'live_chat'          => $tristate,
            'email_support'      => $tristate,
            'support_email'      => ['nullable', 'email:rfc', 'max:255'],
            'support_languages'  => $list,
            'support_languages.*' => $listItem,
        ];

        foreach (CasinoDetail::TOOLS as $tool) {
            $rules[$tool] = $tristate;
        }

        return $rules;
    }
}
