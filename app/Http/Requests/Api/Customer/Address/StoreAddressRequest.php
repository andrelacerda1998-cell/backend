<?php

namespace App\Http\Requests\Api\Customer\Address;

class StoreAddressRequest extends UpdateRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'main_address' => 'nullable|boolean',
        ];
    }
}
