<?php

namespace App\Models;

use App\Models\GeneralSettings\ServicesType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha de uma visita com vários serviços.
 *
 * Sem preço próprio: o preço é o da visita inteira. Os minutos ficam
 * congelados no pedido (tempo do tipo × quantidade, nesse momento).
 */
class ServiceItem extends Model
{
    protected $fillable = [
        'service_id',
        'services_type_id',
        'quantity',
        'minutes',
        'position',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'minutes' => 'integer',
        'position' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServicesType::class, 'services_type_id');
    }

    public function payload(string $language = 'pt-pt'): array
    {
        return [
            'id' => $this->id,
            'service_type_id' => $this->services_type_id,
            'name' => $this->serviceType?->getTranslation('name', $language),
            'quantity' => $this->quantity,
            'minutes' => $this->minutes,
        ];
    }
}
