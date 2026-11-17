<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

use App\Perfume\Models\Order;
use Illuminate\Validation\Rule;

class OrderStatusRequest extends PerfumeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status'  => ['sometimes', Rule::in(Order::STATUSES)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
