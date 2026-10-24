<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Activate, mute (optionally until a time) or ban a forum member. */
class UpdateForumMemberStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', 'in:active,muted,banned'],
            'until' => ['nullable', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
