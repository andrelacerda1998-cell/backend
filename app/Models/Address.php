<?php

namespace App\Models;

use App\Enums\Services\AddressType;
use App\Models\GeneralSettings\AllowedZone;
use App\Observers\AddressObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy(AddressObserver::class)]
class Address extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'address_name',
        'street_name',
        'street_number',
        'additional_info',
        'postal_code',
        'city',
        'municipality',
        'state',
        'country',
        'latitude',
        'longitude',
        'main_address',
        'address_type',
    ];

    protected $casts = [
        'address_type' => AddressType::class,
    ];

    public function allowedZone()
    {
        return $this->belongsTo(AllowedZone::class, 'municipality', 'city');
    }
}
