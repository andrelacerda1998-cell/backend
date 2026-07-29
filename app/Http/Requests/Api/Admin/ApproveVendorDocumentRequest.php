<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ApproveVendorDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A autorização já foi feita pelo middleware admin.api (rota inteira).
        return true;
    }

    public function rules(): array
    {
        return [
            // Opcional, tal como no formulário do Filament (DatePicker sem ->required()).
            'expiration_date' => ['nullable', 'date'],
        ];
    }
}
