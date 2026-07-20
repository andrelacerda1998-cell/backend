<?php

namespace App\Observers;

use App\Models\Schedule\ScheduleAvailable;
use App\Models\VendorScheduleSearch;

class ScheduleAvailableObserver
{
    /**
     * Handle the ScheduleAvailable "created" event.
     */
    public function created(ScheduleAvailable $scheduleAvailable): void
    {
        $this->updateVendorScheduleIndex($scheduleAvailable);
    }

    /**
     * Handle the ScheduleAvailable "updated" event.
     */
    public function updated(ScheduleAvailable $scheduleAvailable): void
    {
        $this->updateVendorScheduleIndex($scheduleAvailable);
    }

    /**
     * Handle the ScheduleAvailable "deleted" event.
     */
    public function deleted(ScheduleAvailable $scheduleAvailable): void
    {
        $this->updateVendorScheduleIndex($scheduleAvailable);
    }

    /**
     * Update the VendorScheduleSearch index for the vendor.
     */
    private function updateVendorScheduleIndex(ScheduleAvailable $scheduleAvailable): void
    {
        $vendorId = $scheduleAvailable->vendor_id;

        if ($vendorId) {
            $vendorScheduleSearch = VendorScheduleSearch::find($vendorId);
            if ($vendorScheduleSearch) {
                $vendorScheduleSearch->searchable();
            }
        }
    }
}
