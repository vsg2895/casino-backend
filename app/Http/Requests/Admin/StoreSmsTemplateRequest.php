<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\SmsTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSmsTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route is already behind auth:sanctum.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                // Unique so the send dialog's dropdown can never offer two
                // entries an admin cannot tell apart.
                Rule::unique('sms_templates', 'name')->ignore($this->templateId()),
            ],
            'body' => [
                'required',
                'string',
                'min:1',
                // Twilio's ceiling for a concatenated message. Same limit the
                // send endpoint enforces, so a template can never be saved in a
                // state that the send would reject.
                'max:' . (int) config('sms.max_body_length', 1600),
            ],
            'status' => ['sometimes', Rule::in(SmsTemplate::STATUSES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique'   => 'A template with that name already exists.',
            'body.required' => 'Enter the message text.',
            'body.max'      => 'An SMS cannot exceed :max characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Trimmed on the way in: trailing whitespace is invisible in the editor
        // but can push a message over a 160-character segment boundary and
        // silently double the per-recipient cost.
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'body' => trim((string) $this->input('body')),
        ]);
    }

    /**
     * The id of the template being edited, so the uniqueness rule can ignore it.
     * Null on create.
     */
    protected function templateId(): ?int
    {
        $record = $this->route('sms_template');

        if ($record instanceof SmsTemplate) {
            return (int) $record->id;
        }

        return is_numeric($record) ? (int) $record : null;
    }
}
