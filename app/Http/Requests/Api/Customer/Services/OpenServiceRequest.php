<?php

namespace App\Http\Requests\Api\Customer\Services;

use Illuminate\Foundation\Http\FormRequest;

class OpenServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'vendor_id' => 'integer|required|exists:App\Models\Vendor,id',
            'service_type' => 'integer|required|exists:App\Models\GeneralSettings\ServicesType,id',
            'payment_method' => 'integer|nullable|exists:RwInteractive\PayshopSdk\Models\PaymentMethod,id',
            'mbway_phone' => 'string|nullable',
            'scheduled' => 'boolean',
            'schedule' => 'array|required_if:scheduled,true',
            'schedule.scheduled_day' => 'required_if:scheduled,true|date',
            'schedule.scheduled_time_start' => 'required_if:scheduled,true|date_format:H:i',
            'schedule.scheduled_time_end' => 'required_if:scheduled,true|date_format:H:i|after:schedule.scheduled_time_start',
            // A app já enviava customer_notes há muito, mas o campo não estava
            // aqui nem era escrito em createService(): o cliente escrevia as
            // instruções de acesso ("campainha do 2.º direito") e ninguém as
            // lia. Sem esta linha o Laravel descarta-o em silêncio.
            'customer_notes' => 'string|nullable|max:1000',
            // Unidades do mesmo serviço ("2 reparações de torneira"). O teto de
            // 10 não é técnico: acima disso deixa de ser uma visita e passa a
            // ser uma obra, que precisa de orçamento e não de checkout.
            'quantity' => 'integer|nullable|min:1|max:10',
            // Ids das fotos já carregadas em /customer/services/photos. A
            // propriedade e a coleção são reconfirmadas ao anexar — este limite
            // é conveniência, não segurança.
            'photo_ids' => 'array|nullable|max:5',
            'photo_ids.*' => 'integer',
            'voucher_id' => 'integer|nullable|exists:App\Models\Voucher,id',
            'address_id' => 'nullable|integer|exists:addresses,id',
            'address' => 'array|nullable',
            'address.name' => 'string|nullable',
            'address.latitude' => 'numeric|required_with:address',
            'address.longitude' => 'numeric|required_with:address',
            'address.street_name' => 'string|nullable',
            'address.street_number' => 'string|nullable',
            'address.additional_info' => 'string|nullable',
            'address.postal_code' => 'string|nullable',
            'address.city' => 'string|nullable',
            'address.state' => 'string|nullable',
            'address.country' => 'string|nullable',
        ];
    }
}
