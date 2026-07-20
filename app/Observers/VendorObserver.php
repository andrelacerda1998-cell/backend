<?php

namespace App\Observers;

use App\Enums\Schedule\ScheduleDay;
use App\Models\Schedule\ScheduleAvailable;
use App\Models\Schedule\ScheduleDays;
use App\Models\Vendor;
use App\Models\VendorScheduleSearch;

class VendorObserver
{
    public function saved(Vendor $vendor): void
    {
        $vendor->searchable();

        // Also update the schedule search index
        $this->updateScheduleSearchIndex($vendor);
    }

    /**
     * Update the VendorScheduleSearch index for schedule-based searches.
     */
    private function updateScheduleSearchIndex(Vendor $vendor): void
    {
        $vendorScheduleSearch = VendorScheduleSearch::find($vendor->id);
        if ($vendorScheduleSearch) {
            $vendorScheduleSearch->searchable();
        }
    }

    /*    public function updated(Vendor $vendor): void
        {
            $vendor->updateRatting();
            $vendor->refresh();
            $vendor->load('servicesTypes', 'averageRating');
            $vendor->searchable();
        }*/

    public function created(Vendor $vendor): void
    {
        $days = ScheduleDays::query()->pluck('id', 'day_name')->toArray();
        $weekendDays = [ScheduleDay::SATURDAY->value, ScheduleDay::SUNDAY->value];

        foreach ($days as $dayName => $dayId) {
            ScheduleAvailable::query()->create([
                'vendor_id' => $vendor->id,
                'day_id' => $dayId,
                'auto_accept' => true,
                'is_enabled' => ! in_array($dayName, $weekendDays),
            ]);
        }
    }
}
