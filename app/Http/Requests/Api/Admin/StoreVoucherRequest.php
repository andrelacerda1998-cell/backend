<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A coluna 'name' na BD é VARCHAR(30) -- o form do Filament valida até 255,
            // o que deixa passar nomes que rebentam com erro de BD só ao gravar.
            'name' => ['required', 'string', 'max:30'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'discount_percentage' => ['required', 'numeric', 'min:1', 'max:100'],
            'valid_services' => ['required', 'array', 'min:1'],
            'valid_services.*' => ['string', 'in:scheduled,immediate'],
            'is_active' => ['boolean'],
        ];
    }
}
