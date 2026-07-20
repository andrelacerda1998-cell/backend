<?php

namespace App\Http\Requests\Api\Auth\Registration;

use Illuminate\Foundation\Http\FormRequest;

class ForgotPasswordRequest extends FormRequest
{
    public function messages()
    {
        return [
            'email.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.email')]),
        ];
    }

    public function rules(): array
    {
        return [
            'email' => 'required',
            'type' => 'required|string|in:customer,vendor',
        ];
    }
}
