<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\WarmupEmail;
use App\Services\Mail\WarmupMailResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The warmup send.
 *
 * Subject and body are gone: the message is now a real site email template,
 * chosen by (site, template) and rendered at send time, so warmup traffic looks
 * like the operator's genuine mail instead of hand-typed prose.
 *
 * `count` is OPTIONAL. Omitted (or null) means "every address on the list"; a
 * number takes that many, least-recently-contacted first.
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
            'site_id' => ['required', 'integer', Rule::exists('sites', 'id')->whereNull('deleted_at')],

            // Whitelist comes from the resolver, so the allowed set is declared
            // in exactly one place — the dropdown, this rule and the send path
            // all read it.
            'template' => ['required', 'string', Rule::in(WarmupMailResolver::ALLOWED_TEMPLATES)],

            // Null / absent = the whole list. Upper bound is checked in
            // withValidator() so the message can quote the real total.
            'count' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'site_id.required'  => 'Choose which site’s template to send.',
            'site_id.exists'    => 'That site no longer exists.',
            'template.required' => 'Choose which email template to send.',
            'template.in'       => 'That template cannot be used for a warmup send.',
            'count.min'         => 'Enter at least 1 recipient, or leave it empty to send to everyone.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $count = $this->input('count');

            if ($count === null || $count === '') {
                return;
            }

            // Capped at what actually exists — asking for 500 when the list holds
            // 80 should be a clear error, not a silently shorter send.
            $available = WarmupEmail::query()->count();

            if ((int) $count > $available) {
                $validator->errors()->add(
                    'count',
                    $available === 0
                        ? 'The warmup list is empty. Add or import addresses first.'
                        : "There are only {$available} address(es) on the warmup list.",
                );
            }
        });
    }

    /** Recipient cap, or null for the whole list. */
    public function recipientLimit(): ?int
    {
        $count = $this->validated('count');

        return $count === null || $count === '' ? null : (int) $count;
    }
}
