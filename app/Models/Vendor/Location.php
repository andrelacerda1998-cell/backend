<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Location extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'vendors_location';

    protected $fillable = [
        'latitude',
        'longitude',
        'device_id'
    ];
}
