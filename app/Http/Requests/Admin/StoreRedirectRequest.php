<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Redirect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A redirect rule.
 *
 * Paths are normalised BEFORE validation so the unique check and the
 * self-redirect check both see the same shape the database will store. Without
 * that, "/old" and "/old/" would pass the unique rule and then collide on insert.
 */
class StoreRedirectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'source_path'      => Redirect::normalisePath((string) $this->input('source_path')),
            'destination_path' => Redirect::normalisePath((string) $this->input('destination_path')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_path' => [
                'required', 'string', 'max:500', 'regex:#^/#',
                // Scoped to this site: two domains may legitimately redirect the
                // same path to different places.
                Rule::unique('redirects', 'source_path')
                    ->where('site_id', $this->route('site')?->id)
                    ->ignore($this->route('redirect')?->id),
                // A path that redirects to itself is an infinite loop the moment
                // it fires. Caught here rather than in the front end, because the
                // front end should never receive a rule it must defend against.
                'different:destination_path',
            ],
            'destination_path' => ['required', 'string', 'max:500', 'regex:#^(/|https?://)#'],
            'status_code'      => ['sometimes', Rule::in(Redirect::STATUS_CODES)],
            'active'           => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source_path.regex'     => 'The source must be a path starting with "/".',
            'source_path.unique'    => 'This site already has a redirect for that path.',
            'source_path.different' => 'A path cannot redirect to itself.',
            'destination_path.regex' => 'The destination must be a path starting with "/", or a full https:// URL.',
        ];
    }
}
