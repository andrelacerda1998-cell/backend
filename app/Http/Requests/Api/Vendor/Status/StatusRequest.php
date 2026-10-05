<?php

namespace App\Http\Requests\Api\Vendor\Status;

use App\Enums\Vendors\StatusVendor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(StatusVendor::class)],
            // O que o telemóvel diz das permissões. Opcionais: as versões da
            // app que já estão nas lojas não as mandam, e não podem ficar
            // impedidas de ir online por isso.
            'location_enabled' => ['sometimes', 'boolean'],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
