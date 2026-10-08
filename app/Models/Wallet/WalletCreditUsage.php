<?php

namespace App\Models\Wallet;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quanto de um crédito de convites foi usado num serviço (e se já voltou). */
class WalletCreditUsage extends Model
{
    protected $fillable = ['wallet_credit_id', 'service_id', 'amount', 'returned_at'];

    protected $casts = [
        'amount' => 'integer',
        'returned_at' => 'datetime',
    ];

    public function credit(): BelongsTo
    {
        return $this->belongsTo(WalletCredit::class, 'wallet_credit_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
