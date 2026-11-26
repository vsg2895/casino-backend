<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 2: the addresses the page established about its own connection.
 *
 * SPOOFABLE BY CONSTRUCTION. Anyone can POST any address here, which is why
 * every row written from this payload is stored with `source =
 * client_reported` and shown in the admin behind a badge saying so. Validation
 * below rejects values that are not addresses at all; it cannot and does not
 * establish that they are the poster's own.
 */
class StoreSpecialOfferVisitClientIpsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Laravel validates the FAMILY as well as the form, so an IPv6
            // value posted as client_ipv4 fails here rather than being stored
            // under a label that misdescribes it.
            'client_ipv4' => ['nullable', 'ipv4'],
            'client_ipv6' => ['nullable', 'ipv6'],
        ];
    }
}
