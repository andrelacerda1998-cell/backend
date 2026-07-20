<?php

namespace App\Http\Requests\Api\Auth\Registration;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function messages()
    {
        return [
            'email.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.email')]),
            'email.email' => __('request/validation.email', ['attribute' => __('request/validation.attributes.email')]),
            'password.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.password')]),
        ];
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email', //:rfc,dns
            'password' => 'required',
            'type' => 'required|in:customer,vendor',
        ];
    }
}
