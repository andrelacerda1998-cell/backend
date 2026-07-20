<?php

namespace App\Models\GeneralSettings;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AllowedZone extends Model
{
    protected $table = 'allowed_zone';

    protected $fillable = ['city', 'district'];

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendor_allowed_zones')->withTimestamps();
    }
}
