<?php

namespace App\Models\Schedule;

use App\Models\Address;
use App\Models\Vendor;
use App\Observers\ScheduleAvailableObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * @property int $id
 * @property int $day_id
 * @property int $vendor_id
 * @property string $time_start
 * @property string $time_end
 * @property boolean $auto_accept
 * @property boolean $is_enabled
 */
#[ObservedBy(ScheduleAvailableObserver::class)]
class ScheduleAvailable extends Model
{
    protected $table = 'schedule_available';

    protected $fillable = [
        'vendor_id',
        'day_id',
        'auto_accept',
        'time_start',
        'time_end',
        'is_enabled',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function scheduleDay(): BelongsTo
    {
        return $this->belongsTo(ScheduleDays::class, 'day_id');
    }

    public function address(): HasOneThrough
    {
        return $this->hasOneThrough(Address::class, Vendor::class);
    }
}
