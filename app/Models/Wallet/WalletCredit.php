<?php

namespace App\Models\Wallet;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Um crédito de convites na Carteira: quanto entrou, quanto sobra e até quando.
 *
 * O dinheiro em si vive na carteira Bavix `convites` do cliente; isto é o
 * registo que diz que parte dela expira e quando.
 */
class WalletCredit extends Model
{
    protected $fillable = ['user_id', 'amount', 'remaining', 'expires_at', 'expired_at', 'reason', 'source_type', 'source_id'];

    protected $casts = [
        'amount' => 'integer',
        'remaining' => 'integer',
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(WalletCreditUsage::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** Ainda se pode gastar: sobra alguma coisa e o prazo não passou. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('remaining', '>', 0)
            ->whereNull('expired_at')
            ->where('expires_at', '>', now());
    }
}
