<?php

namespace App\Models\GeneralSettings;

use Bavix\Wallet\Traits\HasWallets;
use Illuminate\Database\Eloquent\Model;

class SystemWallet extends Model
{
    use HasWallets;

    protected $fillable = ['name'];

    public function getName()
    {
        return $this->name ?? 'System Wallet';
    }
}
