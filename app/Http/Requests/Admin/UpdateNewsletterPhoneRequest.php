<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Phone\PhoneNumber;
use Illuminate\Validation\Rule;

/**
 * Editing a number on the phone list.
 *
 * Extends the store request so normalisation and the "looks like a real number"
 * check cannot drift between the two forms; the only difference is that the
 * uniqueness check ignores the row being edited, and that the opt-out flag can be
 * cleared here (an admin re-adding someone who asked to be re-subscribed).
 */
class UpdateNewsletterPhoneRequest extends StoreNewsletterPhoneRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        // Replace the bare unique rule with one that ignores this row, so saving
        // an unchanged number is not a validation error.
        $rules['phone'] = array_map(
            fn (mixed $rule): mixed => $rule instanceof \Illuminate\Validation\Rules\Unique
                ? Rule::unique('newsletters_based_on_phone', 'phone')->ignore($this->routeRecordId())
                : $rule,
            $rules['phone'],
        );

        // Optional, so a form that does not send it leaves the flag alone.
        $rules['opted_out'] = ['sometimes', 'boolean'];

        return $rules;
    }

    /**
     * Validated attributes, with `opted_out_at` kept consistent with `opted_out`.
     *
     * Done here rather than in the controller because the two columns are one
     * fact: a number flagged as opted out must carry when that happened, and one
     * that is re-subscribed must not keep a stale timestamp implying it is still
     * opted out.
     *
     * Named `payload()` rather than the more natural `attributes()` — that name is
     * already Laravel's hook for custom validation attribute names, and
     * overriding it here would break every error message this request produces.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        if (! array_key_exists('opted_out', $data)) {
            return $data;
        }

        $data['opted_out_at'] = $data['opted_out'] ? now() : null;

        return $data;
    }

    /** The id of the record being edited, for the uniqueness exception. */
    private function routeRecordId(): ?int
    {
        $record = $this->route('newsletter_phone') ?? $this->route('newsletters_based_on_phone');

        if (is_object($record) && isset($record->id)) {
            return (int) $record->id;
        }

        return is_numeric($record) ? (int) $record : null;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Accept the checkbox's "true"/"1"/"on" as a real boolean; without this
        // the `boolean` rule rejects what the admin panel actually sends.
        if ($this->has('opted_out')) {
            $this->merge([
                'opted_out' => filter_var($this->input('opted_out'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
