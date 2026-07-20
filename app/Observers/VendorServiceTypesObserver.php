<?php

namespace App\Observers;

use App\Models\GeneralSettings\VendorServiceTypes;
use App\Models\Vendor;
use App\Models\VendorScheduleSearch;

class VendorServiceTypesObserver
{
    /**
     * Handle the VendorServiceTypes "created" event.
     */
    public function created(VendorServiceTypes $vendorServiceTypes): void
    {
        $this->updateSearchIndexes($vendorServiceTypes);
    }

    /**
     * Handle the VendorServiceTypes "updated" event.
     */
    public function updated(VendorServiceTypes $vendorServiceTypes): void
    {
        $this->updateSearchIndexes($vendorServiceTypes);
    }

    /**
     * Handle the VendorServiceTypes "deleted" event.
     */
    public function deleted(VendorServiceTypes $vendorServiceTypes): void
    {
        $this->updateSearchIndexes($vendorServiceTypes);
    }

    /**
     * Handle the VendorServiceTypes "restored" event.
     */
    public function restored(VendorServiceTypes $vendorServiceTypes): void
    {
        $this->updateSearchIndexes($vendorServiceTypes);
    }

    /**
     * Update both Vendor and VendorScheduleSearch search indexes.
     */
    private function updateSearchIndexes(VendorServiceTypes $vendorServiceTypes): void
    {
        $vendorId = $vendorServiceTypes->vendor_id;

        if (! $vendorId) {
            return;
        }

        $vendor = Vendor::find($vendorId);
        if ($vendor) {
            $vendor->searchable();
        }

        $vendorScheduleSearch = VendorScheduleSearch::find($vendorId);
        if ($vendorScheduleSearch) {
            $vendorScheduleSearch->searchable();
        }
    }
}
