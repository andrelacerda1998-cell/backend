<?php

namespace App\Http\Requests\Api\Customer\Services;

use Illuminate\Foundation\Http\FormRequest;

class OpenServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => 'integer|required|exists:App\Models\Vendor,id',
            'service_type' => 'integer|required|exists:App\Models\GeneralSettings\ServicesType,id',
            'payment_method' => 'integer|nullable|exists:RwInteractive\PayshopSdk\Models\PaymentMethod,id',
            'mbway_phone' => 'string|nullable',
            'scheduled' => 'boolean',
            'schedule' => 'array|required_if:scheduled,true',
            'schedule.scheduled_day' => 'required_if:scheduled,true|date',
            'schedule.scheduled_time_start' => 'required_if:scheduled,true|date_format:H:i',
            'schedule.scheduled_time_end' => 'required_if:scheduled,true|date_format:H:i|after:schedule.scheduled_time_start',
            'voucher_id' => 'integer|nullable|exists:App\Models\Voucher,id',
            'address' => 'array|nullable',
            'address.name' => 'string|nullable',
            'address.latitude' => 'numeric|required_with:address',
            'address.longitude' => 'numeric|required_with:address',
            'address.street_name' => 'string|nullable',
            'address.street_number' => 'string|nullable',
            'address.additional_info' => 'string|nullable',
            'address.postal_code' => 'string|nullable',
            'address.city' => 'string|nullable',
            'address.state' => 'string|nullable',
            'address.country' => 'string|nullable',
        ];
    }
}
