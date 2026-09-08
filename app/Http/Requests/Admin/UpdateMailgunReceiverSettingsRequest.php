<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\MailgunReceiver;
use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Per-credential receiver targeting and message.
 *
 * `batch_size` is capped at 100 000 — a guard against a mistyped figure, not a
 * throughput limit. {@see \App\Services\MailgunReceiverSelector::stream()} pages
 * the selection with a keyset cursor and holds one chunk in memory at a time, and
 * is documented against exactly that size; the campaign job only selects and
 * dispatches, so a larger run costs more queued batch jobs, not a longer-running
 * one. Pacing is the cooldown's job and the daily claim's, not this number's.
 *
 * The message itself arrives as `message_template` — the authored fields, whose
 * rules live in {@see MailgunReceiverTemplate::rules()} so the field list has one
 * home. `message_html` is NOT accepted from the client: it is rendered from those
 * fields on save, which is what stops arbitrary markup from reaching an inbox.
 */
class UpdateMailgunReceiverSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'send_enabled'    => ['required', 'boolean'],
            'batch_size'      => ['required', 'integer', 'min:1', 'max:100000'],
            'selection_order' => ['required', 'string', Rule::in(MailgunReceiver::ORDERS)],
            // Null means "no cooldown" — the same convention as warmup.
            'cooldown_days'   => ['nullable', 'integer', 'min:0', 'max:365'],
            // `only_active` is gone: every receiver on the list is sendable, so
            // there is no audience left for it to narrow. The column stays in the
            // table untouched — see MailgunReceiver::scopeSendable().
            // Required only when sending is switched ON, so a half-configured
            // credential can be saved as a draft but never run empty.
            'message_subject' => ['required_if:send_enabled,true', 'nullable', 'string', 'max:255'],
            ...MailgunReceiverTemplate::rules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'message_subject.required_if' => 'A subject is required before sending can be enabled.',
            ...MailgunReceiverTemplate::messages(),
        ];
    }
}
