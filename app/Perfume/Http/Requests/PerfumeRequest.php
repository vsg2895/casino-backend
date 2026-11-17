<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for the store's admin form requests. Authorisation is the route's
 * `auth:sanctum` (the same as every other admin endpoint); each subclass
 * only declares rules. `slugRule()` / `seoRules()` keep the repeated
 * pieces in one place.
 */
abstract class PerfumeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return list<string|\Illuminate\Validation\Rules\Unique> */
    protected function slugRule(string $table, bool $required): array
    {
        $unique = \Illuminate\Validation\Rule::unique($table, 'slug');
        $ignore = $this->route()?->parameter($this->routeKey());

        if ($ignore !== null) {
            $unique = $unique->ignore(is_object($ignore) ? $ignore->getKey() : $ignore);
        }

        return [$required ? 'required' : 'sometimes', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $unique];
    }

    /** Route parameter that names the row being edited; overridden where the name differs. */
    protected function routeKey(): string
    {
        return '';
    }

    /** @return array<string, list<string>> */
    protected function seoRules(): array
    {
        return [
            'meta_title'       => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
