<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

class StoreRequest extends PerfumeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name'     => [$req, 'string', 'max:255'],
            'address'  => [$req, 'string', 'max:255'],
            'hours'    => ['nullable', 'string', 'max:255'],
            'map_url'  => ['nullable', 'url', 'max:500'],
            'active'   => ['boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
