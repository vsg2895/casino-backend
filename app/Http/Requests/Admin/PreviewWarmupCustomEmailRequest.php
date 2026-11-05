<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Mail\WarmupCustomContent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Preview of a "Custom HTML" warmup email before it is sent.
 *
 * Applies the same limits as {@see SendWarmupEmailsRequest} to the same two
 * fields, so anything that previews cleanly can also be sent.
 */
class PreviewWarmupCustomEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'custom_subject' => ['required', 'string', 'max:' . (int) config('warmup.custom.max_subject_length', 255)],
            'custom_html'    => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'custom_subject.required' => 'Enter a subject for the custom email.',
            'custom_html.required'    => 'Paste the HTML for the custom email.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $html = $this->input('custom_html');
            $maxBytes = (int) config('warmup.custom.max_html_bytes', 500_000);

            if (is_string($html) && strlen($html) > $maxBytes) {
                $validator->errors()->add(
                    'custom_html',
                    sprintf('The HTML is too large: %s KB, the limit is %s KB.', number_format(strlen($html) / 1000, 1), number_format($maxBytes / 1000)),
                );
            }
        });
    }

    public function customContent(): WarmupCustomContent
    {
        return new WarmupCustomContent(
            (string) $this->validated('custom_subject'),
            (string) $this->validated('custom_html'),
        );
    }
}
