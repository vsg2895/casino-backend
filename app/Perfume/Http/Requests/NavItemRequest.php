<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

use App\Perfume\Models\NavItem;
use Illuminate\Validation\Rule;

class NavItemRequest extends PerfumeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'location'    => [$req, Rule::in(NavItem::LOCATIONS)],
            'group_label' => ['nullable', 'string', 'max:255'],
            'label'       => [$req, 'string', 'max:255'],
            'url'         => [$req, 'string', 'max:255'],
            'active'      => ['boolean'],
            'position'    => ['nullable', 'integer', 'min:0'],
        ];
    }
}
