<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

class ScentNoteRequest extends PerfumeRequest
{
    protected function routeKey(): string
    {
        return 'scent_note';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'name'             => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug'             => $this->slugRule('perfume_scent_notes', $creating),
            'description'      => ['nullable', 'string', 'max:5000'],
            'image_path'       => ['nullable', 'string', 'max:255'],
            'featured_on_home' => ['boolean'],
            'position'         => ['nullable', 'integer', 'min:0'],
            ...$this->seoRules(),
        ];
    }
}
