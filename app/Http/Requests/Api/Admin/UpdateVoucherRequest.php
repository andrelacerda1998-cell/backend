<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Ver StoreVoucherRequest -- coluna 'name' é VARCHAR(30) na BD.
            'name' => ['sometimes', 'required', 'string', 'max:30'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'discount_percentage' => ['sometimes', 'required', 'numeric', 'min:1', 'max:100'],
            'valid_services' => ['sometimes', 'required', 'array', 'min:1'],
            'valid_services.*' => ['string', 'in:scheduled,immediate'],
            'is_active' => ['boolean'],
        ];
    }
}
