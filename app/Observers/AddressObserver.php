<?php

namespace App\Observers;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\Vendor;
use App\Models\VendorScheduleSearch;

class AddressObserver
{
    /**
     * Handle the Address "created" event.
     */
    public function created(Address $address): void
    {
        $this->updateScheduleIndexIfNeeded($address);
    }

    /**
     * Handle the Address "updated" event.
     */
    public function updated(Address $address): void
    {
        $this->updateScheduleIndexIfNeeded($address);
    }

    /**
     * Handle the Address "deleted" event.
     */
    public function deleted(Address $address): void
    {
        $this->updateScheduleIndexIfNeeded($address);
    }

    /**
     * Update the VendorScheduleSearch index if the address is a schedule address.
     */
    private function updateScheduleIndexIfNeeded(Address $address): void
    {
        // Only process schedule addresses
        if ($address->address_type !== AddressType::SCHEDULE_ADDRESS) {
            return;
        }

        // Find the vendor associated with this address
        $vendor = Vendor::whereHas('user', fn ($q) => $q->where('id', $address->user_id))->first();

        if ($vendor) {
            $vendorScheduleSearch = VendorScheduleSearch::find($vendor->id);
            if ($vendorScheduleSearch) {
                $vendorScheduleSearch->searchable();
            }
        }
    }
}
