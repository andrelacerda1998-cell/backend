<?php

namespace App\Models\Schedule;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $day_name
 */
class ScheduleDays extends Model
{
    protected $table = 'schedule_days';

    protected $fillable = [
        'day_name',
    ];

    public function availability(): HasMany
    {
        return $this->hasMany(ScheduleAvailable::class);
    }
}
