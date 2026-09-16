<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Abrir um pedido personalizado: o cliente descreve, o backoffice completa.
 *
 * E o irmao do StartMatchingRequest sem `service_type` e com `description`
 * obrigatoria — sem descricao o backoffice nao tem como definir tempo nem
 * categorias, e o pedido morreria na analise.
 */
class StartCustomMatchingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Minimo curto de proposito: "trocar a fechadura da porta da rua"
            // chega. O maximo e o do `customer_notes`, para caber no mesmo
            // sitio do backoffice.
            'description' => ['required', 'string', 'min:10', 'max:2000'],

            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'scheduled' => ['sometimes', 'boolean'],
            'schedule' => ['nullable', 'required_if:scheduled,true', 'array'],
            'schedule.scheduled_day' => ['required_if:scheduled,true', 'date'],
            'schedule.scheduled_time_start' => ['required_if:scheduled,true', 'string'],

            // Fotos ja carregadas para a coleccao pendente do cliente (ver
            // CustomerServicePhotosController). Se vier vazio, anexam-se todas.
            'photo_ids' => ['sometimes', 'array'],
            'photo_ids.*' => ['integer'],
        ];
    }
}
