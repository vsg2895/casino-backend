<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

class PageRequest extends PerfumeRequest
{
    protected function routeKey(): string
    {
        return 'page';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'title'    => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug'     => $this->slugRule('perfume_pages', $creating),
            'body'     => ['nullable', 'string', 'max:200000'],
            'active'   => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            ...$this->seoRules(),
        ];
    }
}
