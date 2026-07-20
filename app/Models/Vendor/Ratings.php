<?php

namespace App\Models\Vendor;

use App\Models\GeneralSettings\OperationArea;
use Illuminate\Database\Eloquent\Model;

class Ratings extends Model
{
    protected $table = 'vendor_ratings';

    protected $fillable = [
        'vendor_id', 'operation_area_id', 'average_rating', 'total_ratings'
    ];

    public function operationAreas()
    {
        return $this->hasOne(OperationArea::class)->whereNull('operation_areas.deleted_at');
    }
}
