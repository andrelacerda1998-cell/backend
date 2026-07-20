<?php

namespace App\Models\User;

use Illuminate\Database\Eloquent\Model;

class UserBillingInfo extends Model
{
    protected $fillable = [
        'name',
        'nif',
        'address',
        'postal_code',
        'locality'
    ];
}
