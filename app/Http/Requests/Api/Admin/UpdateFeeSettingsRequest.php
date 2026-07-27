<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A autorização já foi feita pelo middleware admin.api (rota inteira).
        return true;
    }

    public function rules(): array
    {
        return [
            'daytime' => ['required', 'integer', 'min:0', 'max:100'],
            'evening' => ['required', 'integer', 'min:0', 'max:100'],
            'night' => ['required', 'integer', 'min:0', 'max:100'],
            'late_night' => ['required', 'integer', 'min:0', 'max:100'],
            'midnight' => ['required', 'integer', 'min:0', 'max:100'],
            // kilometer_price chega em euros (ex: 0.35) tal como no formulário do Filament;
            // o controller converte para cêntimos antes de gravar (RateSettings guarda inteiro).
            'kilometer_price' => ['required', 'numeric', 'min:0'],
            'system_commission' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
