<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One site's byline for one casino. Both people must be authors of THAT site:
 * the route's {site}, never a site_id from the payload.
 */
class UpdateCasinoBylineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $siteId = $this->route('site')?->id;

        return [
            'author_id'        => ['nullable', 'integer', Rule::exists('authors', 'id')->where('site_id', $siteId)],
            'reviewed_by_id'   => ['nullable', 'integer', Rule::exists('authors', 'id')->where('site_id', $siteId)],
            'last_reviewed_at' => ['nullable', 'date'],
        ];
    }
}
