<?php

declare(strict_types=1);

namespace App\Perfume\Http\Requests;

use Illuminate\Validation\Rule;

class SubscriberRequest extends PerfumeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $unique = Rule::unique('perfume_subscribers', 'email');
        $subscriber = $this->route()?->parameter('subscriber');

        if ($subscriber !== null) {
            $unique = $unique->ignore(is_object($subscriber) ? $subscriber->getKey() : $subscriber);
        }

        return [
            'email'  => [$creating ? 'required' : 'sometimes', 'email', 'max:255', $unique],
            'name'   => ['nullable', 'string', 'max:120'],
            'active' => ['boolean'],
        ];
    }
}
