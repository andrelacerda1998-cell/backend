<?php

namespace App\Models\GeneralSettings;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SurveyCity extends Model
{
    protected $fillable = ['city', 'district', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function voters(): BelongsToMany
    {
        return $this->belongsToMany(Vendor::class, 'vendor_city_votes')->withTimestamps();
    }
}
