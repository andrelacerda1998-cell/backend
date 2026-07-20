<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    protected $fillable = [
        'service_id',
        'user_id',
        'message',
        'is_read',
    ];

    protected $hidden = [
        'service_id',
        'message',
    ];
}
