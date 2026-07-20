<?php

namespace App\Models\Auth;

use App\Enums\SmsType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneNumberValidationCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'code' => 'string',
            'type' => SmsType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
