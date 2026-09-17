<?php

namespace App\Repository\Schedule;

use App\Models\Schedule\Schedule;
use App\Enums\Services\AddressType;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ScheduleRepository
{
    public function scheduleAvailableVendorSettings(int $userId): Collection
    {
        $user = User::query()->findOrFail($userId);
        /** @var Vendor $vendor */
        $vendor = $user->vendor()->firstOrFail();

        return $vendor->scheduleAvailable()
            ->select('schedule_available.id', 'schedule_available.vendor_id', 'day_id', 'auto_accept', 'time_start', 'time_end', 'is_enabled')
            ->with([
                'scheduleDay:schedule_days.id,day_name',
                'vendor' => function($query) {
                    $query->select('vendors.id', 'vendors.user_id')
                        ->without(['user', 'servicesTypes', 'operationAreas', 'currentLocation'])
                        ->with('user:id,name');
                },
                // A app le `addresses[0]` para preencher a morada destas
                // definicoes. Desde que a fiscal e a de agendamento coexistem,
                // "a primeira" tanto pode ser uma como outra — e o tecnico via
                // a morada da empresa no ecra dos agendamentos. A de
                // agendamento vem primeiro, e o `address_type` vai junto para
                // nao ser preciso adivinhar.
                'vendor.addresses' => function($query) {
                    $query->select('addresses.id', 'addresses.user_id', 'address_type', 'address_name', 'street_name', 'street_number', 'postal_code', 'city', 'state', 'country')
                        ->orderByRaw("CASE WHEN address_type = ? THEN 0 ELSE 1 END", [AddressType::SCHEDULE_ADDRESS->value]);
                },
            ])
            ->get();
    }

    public function scheduleToVendor(Schedule $schedule): Model
    {
        $scheduleToVendor = $schedule
            ->with([
                'serviceType:id,name',
                'customer:users.id,name',
                'customer.addresses' => function($query) {
                    $query->select('addresses.id', 'addresses.user_id', 'address_name', 'street_name', 'street_number', 'postal_code', 'city', 'state', 'country');
                },
            ])
            ->where('is_pending', '=', 0)
            ->firstOrFail();

        $scheduleDate = Carbon::parse($scheduleToVendor->scheduled_day);
        $now = Carbon::now();

        $scheduleToVendor->date_label = match(true) {
            $scheduleDate->isToday() => 'today',
            $scheduleDate->isTomorrow() => 'tomorrow',
            $scheduleDate->isSameWeek($now) => 'week',
            default => 'week',
        };

        return $scheduleToVendor;
    }
}
