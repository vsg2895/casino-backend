<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Mail\MailgunReceiverTemplate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Unsaved receiver-message fields to render, for either channel.
 *
 * The same rules the settings save uses, from the template's own definition,
 * so a preview can never accept a field the save would reject.
 */
class PreviewReceiverMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return MailgunReceiverTemplate::rules();
    }
}
