<?php

namespace App\Http\Requests\Api\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'latitude' => 'required|between:-90,90',
            'longitude' => 'required|between:-180,180',
            'device_id' => 'required|string',
        ];
    }
}
