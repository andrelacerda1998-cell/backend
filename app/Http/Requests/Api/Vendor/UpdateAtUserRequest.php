<?php

namespace App\Http\Requests\Api\Vendor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAtUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'at_user' => 'required|string|regex:/^\d{9}\/\d+$/',
            'at_password' => 'string'
        ];
    }
}
