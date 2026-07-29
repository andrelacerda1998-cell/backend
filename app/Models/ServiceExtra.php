<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tempo extra / peças pedidos pelo técnico durante o serviço.
 * Só contam para o valor depois de aprovados pelo cliente.
 */
class ServiceExtra extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['service_id', 'type', 'description', 'minutes', 'amount', 'status', 'rejection_reason', 'resolved_at'];

    protected $casts = [
        'resolved_at' => 'datetime',
        'minutes' => 'integer',
        'amount' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
