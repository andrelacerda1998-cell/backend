<?php

namespace App\Models\Referral;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O código pessoal de convite de um cliente (ex.: ANA7K2). */
class ReferralCode extends Model
{
    protected $fillable = ['user_id', 'code'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
