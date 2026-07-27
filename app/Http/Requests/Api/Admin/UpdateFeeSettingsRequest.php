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
            // NOTA: estes não são percentagens limitadas a 100 -- são multiplicadores da
            // tarifa base por período do dia (ex: madrugada = 190% da tarifa diurna). Os
            // valores por omissão em produção já passam de 100 (night=150, late_night=190,
            // midnight=190) -- um max:100 aqui rejeitava os próprios valores atuais. O
            // formulário do Filament também não tem limite superior, só ->integer()->required().
            'daytime' => ['required', 'integer', 'min:0'],
            'evening' => ['required', 'integer', 'min:0'],
            'night' => ['required', 'integer', 'min:0'],
            'late_night' => ['required', 'integer', 'min:0'],
            'midnight' => ['required', 'integer', 'min:0'],
            // kilometer_price chega em euros (ex: 0.35) tal como no formulário do Filament;
            // o controller converte para cêntimos antes de gravar (RateSettings guarda inteiro).
            'kilometer_price' => ['required', 'numeric', 'min:0'],
            'system_commission' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
