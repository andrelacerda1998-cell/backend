<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um passo na vida de um pedido. Ver RegistoDeEventos. */
class ServiceEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['service_id', 'tipo', 'estado_de', 'estado_para', 'vendor_id', 'dados', 'ocorreu_em'];

    protected $casts = [
        'dados' => 'array',
        'ocorreu_em' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
