<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\EmailSchedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailScheduleRequest extends FormRequest
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
            'site_id'       => ['required', 'integer', 'exists:sites,id'],
            'name'          => ['nullable', 'string', 'max:120'],

            // Audience is EITHER a sign-up date window OR the newest N subscribers.
            'date_filter'   => ['nullable', 'required_without:limit', Rule::in(EmailSchedule::DATE_FILTERS)],
            'specific_date' => [
                'nullable',
                'required_if:date_filter,' . EmailSchedule::FILTER_SPECIFIC,
                'date_format:Y-m-d',
            ],
            // Required only when there is no date filter; caps the newest-N send.
            'limit'         => ['nullable', 'required_without:date_filter', 'integer', 'min:1', 'max:100000'],

            'frequency'     => ['required', Rule::in(EmailSchedule::FREQUENCIES)],
            'time'          => ['required', 'date_format:H:i'],
            'day_of_week'   => [
                'nullable',
                'required_if:frequency,' . EmailSchedule::FREQ_WEEKLY,
                'integer', 'between:0,6',
            ],
            'day_of_month'  => [
                'nullable',
                'required_if:frequency,' . EmailSchedule::FREQ_MONTHLY,
                'integer', 'between:1,31',
            ],

            'active'        => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Treat an empty-string date_filter from the UI as "no filter".
        $dateFilter = $this->date_filter === '' ? null : $this->date_filter;

        // Null out fields irrelevant to the chosen audience / cadence so stale
        // values from the UI don't linger. date_filter and limit are mutually
        // exclusive: keep the limit only when there is no date filter.
        $this->merge([
            'date_filter'   => $dateFilter,
            'specific_date' => $dateFilter === EmailSchedule::FILTER_SPECIFIC ? $this->specific_date : null,
            'limit'         => $dateFilter === null ? $this->limit : null,
            'day_of_week'   => $this->frequency === EmailSchedule::FREQ_WEEKLY ? $this->day_of_week : null,
            'day_of_month'  => $this->frequency === EmailSchedule::FREQ_MONTHLY ? $this->day_of_month : null,
        ]);
    }
}
