<?php

declare(strict_types=1);

namespace App\Http\Requests\Public\Forum;

use App\Support\Forum\ForumContent;
use Illuminate\Foundation\Http\FormRequest;

class StoreForumPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body'      => ['required', 'string', 'min:2', 'max:' . ForumContent::MAX_POST_LENGTH],
            // Null for a top-level post. Validated for real in the service,
            // which also enforces that the parent is itself top-level — the one
            // rule that keeps nesting at a single level.
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'website'   => ['present', 'max:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required'   => 'Write something before posting.',
            'body.max'        => 'That post is too long — keep it under ' . number_format(ForumContent::MAX_POST_LENGTH) . ' characters.',
            'website.max'     => 'That submission looked automated.',
            'website.present' => 'That submission looked automated.',
        ];
    }
}
