<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

class CategoryRequest extends PerfumeRequest
{
    protected function routeKey(): string
    {
        return 'category';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name'        => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug'        => $this->slugRule('perfume_categories', $creating),
            'title'       => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'image_path'  => ['nullable', 'string', 'max:255'],
            'in_header'   => ['boolean'],
            'active'      => ['boolean'],
            'position'    => ['nullable', 'integer', 'min:0'],
            ...$this->seoRules(),
        ];
    }
}
