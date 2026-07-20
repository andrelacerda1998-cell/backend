<?php

namespace App\Http\Requests\Api\Vendor\Schedule;

use Illuminate\Foundation\Http\FormRequest;

class UpdateScheduleAvailability extends FormRequest
{
    public function rules(): array
    {
        return [
            'address' => 'required|array',
            'address.street_name' => 'required|string',
            'address.street_number' => 'required|string',
            'address.city' => 'required|string',
            'address.postal_code' => 'required|string',
            'address.address_name' => 'required|string',

            'user_id' => 'required|integer|exists:users,id',

            'available_days' => 'required|array',
            'available_days.*' => 'required|array:auto_accept,time_start,time_end,is_enabled',
            'available_days.*.auto_accept' => 'required|boolean',
            'available_days.*.time_start' => 'required|date_format:H:i',
            'available_days.*.time_end' => 'required|date_format:H:i|after:available_days.*.time_start',
            'available_days.*.is_enabled' => 'required|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->available_days)) {
            $this->merge([
                'available_days' => json_decode($this->available_days, true) ?? [],
            ]);
        }
    }
}
