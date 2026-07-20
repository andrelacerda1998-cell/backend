<?php

namespace App\Http\Requests\Api\Customer;

use App\Rules\NifRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBillingInfoRequest extends FormRequest
{
    public function messages()
    {
        return [
            'name.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.name')]),
            'name.max' => __('request/validation.max.string', ['attribute' => __('request/validation.attributes.name'), 'max' => 50]),
            'nif.string' => __('request/validation.string', ['attribute' => __('request/validation.attributes.nif')]),
            'nif.custom_nif_rule' => __('request/validation.custom.nif_rule', ['attribute' => __('request/validation.attributes.nif')]),
            'address.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.address')]),
            'address.max' => __('request/validation.max.string', ['attribute' => __('request/validation.attributes.address'), 'max' => 255]),
            'postal_code.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.postal_code')]),
            'postal_code.max' => __('request/validation.max.string', ['attribute' => __('request/validation.attributes.postal_code'), 'max' => 10]),
            'locality.required' => __('request/validation.required', ['attribute' => __('request/validation.attributes.locality')]),
            'locality.max' => __('request/validation.max.string', ['attribute' => __('request/validation.attributes.locality'), 'max' => 100]),
        ];
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:50',
            'nif' => ['string', new NifRule],
            'address' => 'required|string|max:255',
            'postal_code' => 'required|string|max:10',
            'locality' => 'required|string|max:100',
        ];
    }
}
