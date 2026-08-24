<?php

namespace App\Models\Vendor;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um dia em que o técnico não está disponível, apesar de ser um dia ativo na
 * disponibilidade semanal. Ver a migração para o porquê.
 */
class VendorUnavailableDay extends Model
{
    protected $table = 'vendor_unavailable_days';

    protected $fillable = ['vendor_id', 'day', 'reason'];

    protected $casts = ['day' => 'date'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
