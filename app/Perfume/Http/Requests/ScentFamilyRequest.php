<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

class ScentFamilyRequest extends PerfumeRequest
{
    protected function routeKey(): string
    {
        return 'scent_family';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name'     => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug'     => $this->slugRule('perfume_scent_families', $creating),
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
