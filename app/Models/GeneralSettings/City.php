<?php

namespace App\Models\GeneralSettings;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class City extends Model
{
    protected $fillable = ['name', 'district', 'suggested', 'active'];

    protected function casts(): array
    {
        return [
            'suggested' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** Tecnicos que aceitam trabalhar nesta cidade. */
    public function availableVendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendor_available_cities')->withTimestamps();
    }

    /** Tecnicos que puseram esta cidade no top 3 de maior interesse. */
    public function preferredVendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendor_preferred_cities')
            ->withPivot('position')
            ->withTimestamps();
    }
}
