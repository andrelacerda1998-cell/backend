<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use NotificationChannels\Expo\ExpoPushToken;
use OwenIt\Auditing\Contracts\Auditable;

class Device extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $fillable = [
        'expo_token',
        'user_id',
        'device_name',
    ];

    protected $casts = [
        'expo_token' => ExpoPushToken::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
