<?php

namespace App\Models\Auth;

use Illuminate\Database\Eloquent\Model;

class Authentications extends Model
{
    protected $casts = [
        'rsa' => 'encrypted'
    ];
}
