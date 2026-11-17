<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

class PerfumerRequest extends PerfumeRequest
{
    protected function routeKey(): string
    {
        return 'perfumer';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name'        => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug'        => $this->slugRule('perfume_perfumers', $creating),
            'description' => ['nullable', 'string', 'max:5000'],
            'image_path'  => ['nullable', 'string', 'max:255'],
            ...$this->seoRules(),
        ];
    }
}
