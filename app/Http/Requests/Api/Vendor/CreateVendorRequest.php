<?php

namespace App\Http\Requests\Api\Vendor;

use App\Rules\NifRule;
use App\Rules\UniquePhoneNumber;
use App\Services\Common\PhoneLoginSmsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class CreateVendorRequest extends FormRequest
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
            'username.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.username')]),
            'username.unique' => __('request/validation.unique', ['attribute' => __('request/validation.attributes.username')]),
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
            'operation_areas.array' => __('request/validation.array', ['attribute' => __('request/validation.attributes.operation_areas')]),
            'operation_areas.*.integer' => __('request/validation.integer', ['attribute' => __('request/validation.attributes.operation_areas')]),
            'operation_areas.*.exists' => __('request/validation.exists', ['attribute' => __('request/validation.attributes.operation_areas')]),
            'price_rate.numeric' => __('request/validation.numeric', ['attribute' => __('request/validation.attributes.price_rate')]),
            'documents.array' => __('request/validation.array', ['attribute' => __('request/validation.attributes.documents')]),
            'documents.*.file.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.document_file')]),
            'documents.*.file.file' => __('request/validation.file', ['attribute' => __('request/validation.attributes.document_file')]),
            'documents.*.file.mimes' => __('request/validation.mimes', ['attribute' => __('request/validation.attributes.document_file'), 'values' => 'pdf,jpg,jpeg,png,heic,heif,webp']),
            'documents.*.file.max' => __('request/validation.max.file', ['attribute' => __('request/validation.attributes.document_file'), 'max' => 10240]),
            'documents.*.document_id.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.document_id')]),
            'documents.*.document_id.integer' => __('request/validation.integer', ['attribute' => __('request/validation.attributes.document_id')]),
            'documents.*.document_id.exists' => __('request/validation.exists', ['attribute' => __('request/validation.attributes.document_id')]),
            'services_types.array' => __('request/validation.array', ['attribute' => __('request/validation.attributes.services_types')]),
            'services_types.*.integer' => __('request/validation.integer', ['attribute' => __('request/validation.attributes.services_types')]),
            'services_types.*.exists' => __('request/validation.exists', ['attribute' => __('request/validation.attributes.services_types')]),
        ];
    }

    public function rules(): array
    {
        return [
            'username' => 'required|string|max:50|unique:vendors,username',
            'name' => 'required|string|max:50',
            // 'date_birthday' => 'date|before_or_equal:today',
            // 'nif' => ['string', new NifRule],
            'email' => ['required', 'email', 'unique:users,email', 'not_regex:/^imp\.\d+@piquetapp\.pt$/i'],
            'phone_number' => ['string', 'nullable', new UniquePhoneNumber],
            // 'gender_id' => 'nullable|integer|exists:genders,id',
            'language' => 'string',
            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
            'operation_areas' => 'array',
            'operation_areas.*' => 'integer|exists:operation_areas,id',
            'price_rate' => 'numeric|nullable',
            'documents' => 'array',
            'documents.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,heic,heif,webp', 'max:10240'],
            'documents.*.document_id' => ['required', 'integer', 'exists:documents,id'],
            'services_types' => 'array',
            'services_types.*' => 'integer|exists:services_types,id',

        ];
    }
}
