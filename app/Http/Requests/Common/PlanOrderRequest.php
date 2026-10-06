<?php

namespace App\Http\Requests\Common;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plano de visitas de um cesto, antes de pedir. Público: o convidado vê o
 * plano antes de confirmar o telemóvel.
 */
class PlanOrderRequest extends FormRequest
{
    /** O cesto tem um limite: acima disto é um pedido personalizado. */
    public const MAX_LINHAS = 6;

    public function rules(): array
    {
        return array_merge(self::regrasDasLinhas(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
        ], self::regrasDaHora());
    }

    public static function regrasDasLinhas(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINHAS],
            'items.*.service_type_id' => ['required', 'integer', 'distinct', 'exists:services_types,id'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ];
    }

    public static function regrasDaHora(): array
    {
        return [
            'scheduled' => ['sometimes', 'boolean'],
            'schedule' => ['nullable', 'required_if:scheduled,true', 'array'],
            'schedule.scheduled_day' => ['required_if:scheduled,true', 'date'],
            'schedule.scheduled_time_start' => ['required_if:scheduled,true', 'string'],
        ];
    }
}
