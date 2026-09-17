<?php

namespace App\Http\Requests\Api\Vendor\Schedule;

use Illuminate\Foundation\Http\FormRequest;

/**
 * So a morada de onde o tecnico sai para um servico agendado.
 *
 * Separada do `UpdateScheduleAvailability` porque aquele exige tambem os dias
 * da semana: pedir ao tecnico que configure a agenda inteira para poder gravar
 * a morada era obriga-lo a decidir duas coisas ao mesmo tempo, no meio do
 * registo — e a morada e a unica das duas de que o matching precisa.
 */
class UpdateScheduleAddress extends FormRequest
{
    public function rules(): array
    {
        return [
            'street_name' => 'required|string',
            'street_number' => 'required|string',
            'city' => 'required|string',
            'postal_code' => 'required|string',
        ];
    }
}
