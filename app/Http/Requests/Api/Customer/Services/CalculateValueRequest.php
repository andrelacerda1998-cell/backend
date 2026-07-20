<?php

namespace App\Http\Requests\Api\Customer\Services;

use Illuminate\Foundation\Http\FormRequest;

class CalculateValueRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => 'integer|required|exists:App\Models\Vendor,id',
            'service_type' => 'integer|required|exists:App\Models\GeneralSettings\ServicesType,id',
            'scheduled' => 'boolean',
            'voucher_id' => 'integer|nullable|exists:App\Models\Voucher,id',
            'is_guest' => 'nullable|boolean',
            'address' => 'array|nullable',
            'address.latitude' => 'numeric|required_with:address',
            'address.longitude' => 'numeric|required_with:address',
            'address.street_name' => 'string|nullable',
            'address.postal_code' => 'string|nullable',
            'address.city' => 'string|nullable',
        ];
    }
}
