<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

use App\Perfume\Models\HomeCard;
use Illuminate\Validation\Rule;

class HomeCardRequest extends PerfumeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'kind'       => [$req, Rule::in(HomeCard::KINDS)],
            'number'     => ['nullable', 'string', 'max:4'],
            'title'      => [$req, 'string', 'max:255'],
            'text'       => ['nullable', 'string', 'max:2000'],
            'link_label' => ['nullable', 'string', 'max:255'],
            'url'        => ['nullable', 'string', 'max:255'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'active'     => ['boolean'],
            'position'   => ['nullable', 'integer', 'min:0'],
        ];
    }
}
