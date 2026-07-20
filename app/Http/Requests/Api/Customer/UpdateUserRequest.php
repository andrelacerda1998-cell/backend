<?php

namespace App\Http\Requests\Api\Customer;

use App\Rules\NifRule;
use App\Rules\UniquePhoneNumber;
use App\Services\Common\PhoneLoginSmsService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
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
            'name.max' => __('request/validation.max.string', ['attribute' => __('request/validation.attributes.name'), 'max' => 50]),
            'date_birthday.date' => __('request/validation.date', ['attribute' => __('request/validation.attributes.date_birthday')]),
            'date_birthday.before_or_equal' => __('request/validation.before_or_equal', ['attribute' => __('request/validation.attributes.date_birthday'), 'date' => __('request/validation.attributes.today')]),
            'nif.string' => __('request/validation.string', ['attribute' => __('request/validation.attributes.nif')]),
            'nif.custom_nif_rule' => __('request/validation.custom.nif_rule', ['attribute' => __('request/validation.attributes.nif')]),
            'phone_number.string' => __('request/validation.string', ['attribute' => __('request/validation.attributes.phone_number')]),
            'avatar.max' => __('request/validation.max.file', ['attribute' => __('request/validation.attributes.avatar'), 'max' => 5120]),
            'gender_id.exists' => __('request/validation.exists', ['attribute' => __('request/validation.attributes.gender_id'), 'values' => __('request/validation.attributes.genders')]),
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'email' => ['nullable', 'email', 'unique:users,email,'.auth()->id()],
            'date_birthday' => 'nullable|date|before_or_equal:today',
            'nif' => ['nullable', 'string', new NifRule],
            'phone_number' => ['string', 'nullable', new UniquePhoneNumber(auth()->id())],
            // 'language' => 'string',
            'gender_id' => 'nullable|integer|exists:genders,id',
            // 'password' => [
            //     'required',
            //     'string',
            //     // Password::min(8)
            //     //     ->letters()
            //     //     ->mixedCase()
            //     //     ->numbers()
            //     //     ->symbols(),
            // ],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
