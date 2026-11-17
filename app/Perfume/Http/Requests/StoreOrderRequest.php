<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

/**
 * A basket submitted from the storefront. Only ids and quantities are
 * accepted: prices and names are read from the database by
 * {@see \App\Perfume\Services\OrderWriter}, never from this payload.
 */
class StoreOrderRequest extends PerfumeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_name'     => ['required', 'string', 'max:120'],
            'phone'             => ['required', 'string', 'max:40'],
            'email'             => ['nullable', 'email', 'max:255'],
            'address'           => ['nullable', 'string', 'max:255'],
            'comment'           => ['nullable', 'string', 'max:2000'],
            'items'             => ['required', 'array', 'min:1', 'max:50'],
            'items.*.variant_id' => ['required', 'integer', 'exists:perfume_product_variants,id'],
            'items.*.qty'       => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_name.required' => 'Please tell us your name.',
            'phone.required'         => 'Please leave a phone number so we can confirm the order.',
            'items.required'         => 'Your cart is empty.',
        ];
    }
}
