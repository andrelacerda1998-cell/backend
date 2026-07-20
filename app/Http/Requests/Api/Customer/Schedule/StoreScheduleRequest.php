<?php

namespace App\Http\Requests\Api\Customer\Schedule;

use App\Rules\NifRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreScheduleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => 'required|exists:vendors,id',
            'customer_id' => 'required|exists:users,id',
            'scheduled_day' => 'required|string',
            'service_type_id' => 'required|exists:services_types,id',
            'service_id' => 'nullable|exists:services,id',
            'scheduled_time_start' => 'required|date_format:H:i',
            'scheduled_time_end' => 'required|date_format:H:i|after:scheduled_time_start',
            'nif' => ['string','nullable','max:9', new NifRule()],
        ];
    }
}
