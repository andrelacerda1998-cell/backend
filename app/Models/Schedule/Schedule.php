<?php

namespace App\Models\Schedule;

use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Observers\ScheduleObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property int $vendor_id
 * @property int $customer_id
 * @property int $service_type_id
 * @property int $service_id
 * @property Date $scheduled_day
 * @property string $scheduled_time_start
 * @property string $scheduled_time_end
 * @property boolean $is_pending
 */
#[ObservedBy(ScheduleObserver::class)]
class Schedule extends Model
{
    protected $table = 'schedule';

    protected $fillable = [
        'vendor_id',
        'customer_id',
        'service_type_id',
        'service_id',
        'scheduled_day',
        'scheduled_time_start',
        'scheduled_time_end',
        'is_pending',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServicesType::class, 'service_type_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
