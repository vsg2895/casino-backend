<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\WarmupEmail;
use App\Models\WarmupSend;
use App\Services\Mail\WarmupCustomContent;
use App\Services\Mail\WarmupMailResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The warmup send.
 *
 * Subject and body are gone: the message is a real site email template rendered at
 * send time, so warmup traffic looks like the operator's genuine mail instead of
 * hand-typed prose. The SITE is not a parameter — warmup is pinned to
 * config('warmup.site_slug') and the controller resolves it.
 *
 * `count` is OPTIONAL. Omitted (or null) means "every address on the list"; a
 * number takes that many, MOST RECENTLY ADDED first.
 *
 * `cooldown_days` only applies to a limited run. It skips addresses successfully
 * contacted within that many days, which is what stops the newest addresses
 * absorbing every send. A whole-list run has no cooldown by definition — the
 * admin asked for every address — so the value is discarded rather than rejected
 * when `count` is absent, matching a UI that hides the control in that state.
 *
 * `custom_subject` / `custom_html` are required for the "Custom HTML" template
 * and ignored for every other one ({@see customContent()} returns null then).
 * Same limits as the preview endpoint, so what previewed can be sent.
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
            // No site_id: warmup is pinned to config('warmup.site_slug') and the
            // endpoint resolves it itself, so a caller cannot pick a brand.

            // Whitelist comes from the resolver, so the allowed set is declared
            // in exactly one place — the dropdown, this rule and the send path
            // all read it.
            'template' => ['required', 'string', Rule::in(WarmupMailResolver::ALLOWED_TEMPLATES)],

            // The "Custom HTML" template's own content. Byte limit checked in
            // withValidator(): `max` on a string counts characters, and the
            // limit that matters for a mail body is its size on the wire.
            'custom_subject' => [
                'nullable',
                'required_if:template,' . WarmupMailResolver::TYPE_CUSTOM,
                'string',
                'max:' . (int) config('warmup.custom.max_subject_length', 255),
            ],
            'custom_html' => [
                'nullable',
                'required_if:template,' . WarmupMailResolver::TYPE_CUSTOM,
                'string',
            ],

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
            'template.required' => 'Choose which email template to send.',
            'template.in'       => 'That template cannot be used for a warmup send.',
            'count.min'         => 'Enter at least 1 recipient, or leave it empty to send to everyone.',
            'cooldown_days.min' => 'The cooldown must be at least ' . WarmupSend::MIN_COOLDOWN_DAYS . ' day.',
            'cooldown_days.max' => 'The cooldown cannot exceed ' . WarmupSend::MAX_COOLDOWN_DAYS . ' days.',
            'custom_subject.required_if' => 'Enter a subject for the custom email.',
            'custom_html.required_if'    => 'Paste the HTML for the custom email.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $html = $this->input('custom_html');
            $maxBytes = (int) config('warmup.custom.max_html_bytes', 500_000);

            if ($this->input('template') === WarmupMailResolver::TYPE_CUSTOM && is_string($html) && strlen($html) > $maxBytes) {
                $validator->errors()->add(
                    'custom_html',
                    sprintf('The HTML is too large: %s KB, the limit is %s KB.', number_format(strlen($html) / 1000, 1), number_format($maxBytes / 1000)),
                );
            }

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

    /** The custom subject + HTML, or null when the run uses a stored template. */
    public function customContent(): ?WarmupCustomContent
    {
        if ($this->validated('template') !== WarmupMailResolver::TYPE_CUSTOM) {
            return null;
        }

        return new WarmupCustomContent(
            (string) $this->validated('custom_subject'),
            (string) $this->validated('custom_html'),
        );
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
