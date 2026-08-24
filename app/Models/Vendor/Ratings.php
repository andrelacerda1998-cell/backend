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

    protected $casts = [
        // float e não decimal: o cast `decimal` devolve string, e a app do
        // cliente faz `toFixed(1)` sobre o valor — precisa de um número.
        // null significa "ainda sem avaliações", que é diferente de zero.
        'average_rating' => 'float',
        'total_ratings' => 'integer',
    ];

    public function operationAreas()
    {
        return $this->hasOne(OperationArea::class)->whereNull('operation_areas.deleted_at');
    }
}
