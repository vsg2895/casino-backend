<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\MailgunReceiver;
use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The targeting rule and message for one stored SMTP credential.
 *
 * Mirrors {@see UpdateMailgunReceiverSettingsRequest} minus `send_enabled`:
 * this channel has no scheduler, so there is no automatic-sending flag to
 * govern. `message_subject` is therefore plainly nullable rather than
 * conditionally required — a half-configured credential saves as a draft, and
 * {@see \App\Jobs\SendSmtpReceiverCampaignJob::blockedReason()} is what refuses
 * to run it.
 */
class UpdateSmtpReceiverSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'batch_size'      => ['required', 'integer', 'min:1', 'max:10000'],
            'selection_order' => ['required', 'string', Rule::in(MailgunReceiver::ORDERS)],
            // Null means "no cooldown" — the same convention as warmup.
            'cooldown_days'   => ['nullable', 'integer', 'min:0', 'max:365'],
            'message_subject' => ['nullable', 'string', 'max:255'],
            ...MailgunReceiverTemplate::rules(),
        ];
    }
}
