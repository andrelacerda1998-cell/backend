<?php

namespace App\Http\Requests\Api\Auth\Registration;

use App\Rules\NifRule;
use App\Rules\UniquePhoneNumber;
use App\Services\Common\PhoneLoginSmsService;
use Illuminate\Foundation\Http\FormRequest;

class VerifyUserDataToCreateRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('phone_number')) {
            $this->merge(['phone_number' => PhoneLoginSmsService::normalizePhoneNumber($this->input('phone_number'))]);
        }
    }

    public function messages()
    {
        return [
            'name.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.name')]),
            // 'date_birthday.date' => __('request/validation.date', ['attribute' => __('request/validation.attributes.date_birthday')]),
            // 'date_birthday.before_or_equal' => __('request/validation.before_or_equal', ['attribute' => __('request/validation.attributes.date_birthday')]),
            // 'nif.string' => __('request/validation.string', ['attribute' => __('request/validation.attributes.nif')]),
            // 'nif.custom_nif_rule' => __('request/validation.custom.nif_rule', ['attribute' => __('request/validation.attributes.nif')]),
            'email.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.email')]),
            'email.email' => __('request/validation.email', ['attribute' => __('request/validation.attributes.email')]),
            'email.unique' => __('request/validation.unique', ['attribute' => __('request/validation.attributes.email')]),
            'phone_number.string' => __('request/validation.string', ['attribute' => __('request/validation.attributes.phone_number')]),
            'phone_number.unique' => __('request/validation.unique', ['attribute' => __('request/validation.attributes.phone_number')]),
            'password.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.password')]),
            'password.string' => __('request/validation.string', ['attribute' => __('request/validation.attributes.password')]),
            'password.confirmed' => __('request/validation.confirmed', ['attribute' => __('request/validation.attributes.password')]),
            'password.min' => __('request/validation.min.string', ['attribute' => __('request/validation.attributes.password'), 'min' => 8]),
            'password.letters' => __('request/validation.password.letters'),
            'password.mixedCase' => __('request/validation.password.mixed_case'),
            'password.numbers' => __('request/validation.password.numbers'),
            'password.symbols' => __('request/validation.password.symbols'),
            'password.uncompromised' => __('request/validation.password.uncompromised'),
        ];
    }

    public function rules(): array
    {
        return [
            'email' => ['nullable', 'email', 'unique:users,email', 'not_regex:/^imp\.\d+@piquetapp\.pt$/i'],
            // 'nif' => ['string', new NifRule],
            'phone_number' => ['required', 'string', new UniquePhoneNumber],
        ];
    }
}
