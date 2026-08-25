<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Services\Mail\WarmupMailResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The warmup send.
 *
 * Subject and body are gone: the message is a real site email template, chosen by
 * (site, template) and rendered at send time, so warmup traffic looks like the
 * operator's genuine mail instead of hand-typed prose.
 *
 * `count` is OPTIONAL. Omitted (or null) means "every address on the list"; a
 * number takes that many, MOST RECENTLY ADDED first.
 *
 * `cooldown_days` only applies to a limited run. It skips addresses successfully
 * contacted within that many days, which is what stops the newest addresses
 * absorbing every send. A whole-list run has no cooldown by definition — the
 * admin asked for every address — so the value is discarded rather than rejected
 * when `count` is absent, matching a UI that hides the control in that state.
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

            // Bounds come from the model so this rule, the API's advertised
            // maximum and the admin's number input can never disagree.
            'cooldown_days' => [
                'nullable',
                'integer',
                'min:' . WarmupSend::MIN_COOLDOWN_DAYS,
                'max:' . WarmupSend::MAX_COOLDOWN_DAYS,
            ],
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
            'cooldown_days.min' => 'The cooldown must be at least ' . WarmupSend::MIN_COOLDOWN_DAYS . ' day.',
            'cooldown_days.max' => 'The cooldown cannot exceed ' . WarmupSend::MAX_COOLDOWN_DAYS . ' days.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $count = $this->input('count');

            if ($count === null || $count === '') {
                return;
            }

            // Capped at what is ON THE LIST, not at what is currently eligible.
            //
            // Deliberate: asking for 500 when only 80 addresses exist is a typo
            // and deserves an error, but asking for 50 when the cooldown leaves 12
            // eligible is a perfectly ordinary request — that run simply reaches
            // 12. Validating against the eligible set would reject it, and would
            // also be a race, since eligibility moves as other runs finish.
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

    /**
     * Cooldown window in days, or null when none applies.
     *
     * Null whenever the run targets the whole list: "send to every address" and
     * "skip recently contacted addresses" are contradictory instructions, and the
     * explicit choice wins.
     */
    public function cooldownDays(): ?int
    {
        if ($this->recipientLimit() === null) {
            return null;
        }

        $days = $this->validated('cooldown_days');

        return $days === null || $days === '' ? null : (int) $days;
    }
}
