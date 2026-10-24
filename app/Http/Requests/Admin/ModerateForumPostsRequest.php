<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** One moderation action over up to 100 posts. */
class ModerateForumPostsRequest extends FormRequest
{
    /** @var list<string> */
    public const array ACTIONS = ['approve', 'reject', 'spam', 'delete', 'restore'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
