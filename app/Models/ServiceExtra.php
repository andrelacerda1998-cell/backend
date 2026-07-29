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

    protected $fillable = [
        'service_id', 'type', 'description', 'minutes', 'amount', 'status', 'rejection_reason', 'resolved_at',
        'payment_status', 'payment_order_id', 'payment_error', 'charged_at', 'vendor_credited_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'charged_at' => 'datetime',
        'vendor_credited_at' => 'datetime',
        'minutes' => 'integer',
        'amount' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Ordem Payshop dedicada a este extra (criada na aprovação pelo cliente). */
    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(\RwInteractive\PayshopSdk\Models\PaymentOrder::class, 'payment_order_id');
    }

    /** O dinheiro deste extra está efetivamente garantido (capturado ou dispensado)? */
    public function isCharged(): bool
    {
        return in_array($this->payment_status, ['paid', 'not_required'], true);
    }
}
