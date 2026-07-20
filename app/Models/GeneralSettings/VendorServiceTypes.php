<?php

namespace App\Models\GeneralSettings;

use App\Observers\VendorServiceTypesObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(VendorServiceTypesObserver::class)]
class VendorServiceTypes extends Pivot
{
    use SoftDeletes;

    protected $table = 'services_type_vendor';

    public function serviceType(): HasOne
    {
        return $this->hasOne(ServicesType::class);
    }
}
