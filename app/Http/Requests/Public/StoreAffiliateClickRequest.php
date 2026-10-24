<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/** One outbound affiliate click, reported server-side by a site's /go route. */
class StoreAffiliateClickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'casino_slug' => ['required', 'string', 'max:255'],
            'offer_slug' => ['nullable', 'string', 'max:255'],
        ];
    }
}
