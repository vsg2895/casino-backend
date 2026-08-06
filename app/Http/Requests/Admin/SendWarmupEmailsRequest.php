<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The warmup send. Subject and body are supplied per run rather than stored as
 * a template: warmup traffic should look like ordinary correspondence, and
 * varying the wording between runs is part of what makes it effective.
 */
class SendWarmupEmailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'body'    => ['required', 'string', 'max:5000'],
        ];
    }
}
