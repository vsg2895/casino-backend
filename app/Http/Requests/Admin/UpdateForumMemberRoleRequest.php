<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\ForumUser;
use Illuminate\Foundation\Http\FormRequest;

/** A forum member's role. `role` is not fillable; the controller forceFills it. */
class UpdateForumMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'in:'.implode(',', ForumUser::ROLES)],
        ];
    }
}
