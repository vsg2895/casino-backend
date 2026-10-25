<?php

declare(strict_types=1);

namespace App\Http\Requests\UniOne;

use App\Models\UniOne\UniOneApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A UniOne API key. The secret is required on create; see UpdateUniOneKeyRequest. */
class StoreUniOneKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'api_key' => $this->apiKeyRule(),
            'key_type' => ['required', Rule::in(UniOneApiKey::TYPES)],
            'project_id' => ['nullable', 'required_if:key_type,project', 'string', 'max:64'],
            'region' => ['required', Rule::in(UniOneApiKey::REGIONS)],
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'is_active' => ['sometimes', 'boolean'],
            'default_from_email' => ['nullable', 'email', 'max:255'],
            'default_from_name' => ['nullable', 'string', 'max:120'],
            'track_links' => ['sometimes', 'boolean'],
            'track_read' => ['sometimes', 'boolean'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<string> */
    protected function apiKeyRule(): array
    {
        return ['required', 'string', 'max:500'];
    }
}
