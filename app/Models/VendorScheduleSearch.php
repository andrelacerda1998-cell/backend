<?php

namespace App\Models;

use App\Enums\Services\AddressType;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\ScheduleAvailable;
use App\Models\Vendor\Ratings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

/**
 * Virtual model for Meilisearch index with schedule-based geolocation.
 * Uses the vendor's schedule_address instead of real-time location.
 */
class VendorScheduleSearch extends Model
{
    use Searchable;

    protected $table = 'vendors';

    protected $primaryKey = 'id';

    public function searchableAs(): string
    {
        return 'vendors_schedule';
    }

    public function toSearchableArray(): array
    {
        $vendor = Vendor::with([
            'user',
            'servicesTypes',
            'operationAreas',
            'currentLocation',
            'scheduleAvailable.scheduleDay',
            'averageRating',
        ])->find($this->id);

        if (!$vendor) {
            return [];
        }

        $scheduleAddress = $this->getScheduleAddress($vendor);

        $attributes = [
            'id' => $vendor->id,
            'user_id' => $vendor->user_id,
            'status' => $vendor->status->value,
            'can_accept_service' => $vendor->can_accept_service,
        ];

        // Geolocation based on schedule address
        $attributes['_geo'] = [
            'lat' => $scheduleAddress?->latitude ?? 0,
            'lng' => $scheduleAddress?->longitude ?? 0,
        ];

        // Has valid schedule address
        $attributes['has_schedule_address'] = $scheduleAddress !== null
            && $scheduleAddress->latitude !== null
            && $scheduleAddress->longitude !== null;

        // Services types
        $attributes['services_types'] = $vendor->servicesTypes->map(fn ($st) => [
            'id' => $st->id,
            'name' => $st->name,
            'operation_area_id' => $st->operation_area_id,
        ])->toArray();

        // Ratings
        $attributes['ratings'] = $vendor->averageRating->map(fn ($r) => [
            'operation_area_id' => $r->operation_area_id,
            'average_rating' => $r->average_rating,
            'total_ratings' => $r->total_ratings,
        ])->toArray();

        // Chave de ordenação. Quem ainda não tem avaliações vale 0 e não 5:
        // com o 5 fictício, um profissional que nunca trabalhou aparecia à
        // frente de toda a gente numa ordenação descendente.
        //
        // NOTA: isto muda a ordem da pesquisa — a oferta nova passa a aparecer
        // no fim. É o problema de arranque a frio, e a resposta a ele é a vaga
        // reservada do fluxo de seleção (docs/matching.md), não uma nota falsa.
        $attributes['average_rating'] = $vendor->averageRating->first()?->average_rating ?? 0;

        // Schedule availability
        $availability = $this->buildScheduleAvailability($vendor);
        $attributes['schedule_availability'] = $availability['days'];
        $attributes['has_auto_accept'] = $availability['has_auto_accept'];
        $attributes['has_availability_next_week'] = $availability['has_availability_next_week'];

        // Online status
        $attributes['is_online'] = $vendor->status === StatusVendor::ONLINE;

        // Last activity timestamp for filtering recently active vendors
        $attributes['geoTime'] = $vendor->currentLocation?->updated_at?->timestamp ?? 0;

        return $attributes;
    }

    private function getScheduleAddress(Vendor $vendor): ?Address
    {
        return $vendor->addresses()
            ->where('address_type', AddressType::SCHEDULE_ADDRESS)
            ->first();
    }

    private function buildScheduleAvailability(Vendor $vendor): array
    {
        $scheduleAvailable = $vendor->scheduleAvailable;
        $days = [];
        $hasAutoAccept = false;
        $hasAvailabilityNextWeek = false;

        $today = Carbon::now();
        $endOfNextWeek = $today->copy()->addWeek()->endOfWeek();

        foreach ($scheduleAvailable as $schedule) {
            if (!$schedule->is_enabled) {
                continue;
            }

            $dayName = $schedule->scheduleDay?->day_name;
            if (!$dayName) {
                continue;
            }

            $days[] = [
                'day' => $dayName,
                'day_id' => $schedule->day_id,
                'time_start' => $schedule->time_start,
                'time_end' => $schedule->time_end,
                'auto_accept' => $schedule->auto_accept,
                'is_enabled' => $schedule->is_enabled,
            ];

            if ($schedule->auto_accept) {
                $hasAutoAccept = true;
            }

            // Check if this day falls within next week
            $hasAvailabilityNextWeek = true;
        }

        return [
            'days' => $days,
            'has_auto_accept' => $hasAutoAccept,
            'has_availability_next_week' => $hasAvailabilityNextWeek,
        ];
    }

    /**
     * Get vendors that should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        $vendor = Vendor::find($this->id);
        if (!$vendor) {
            return false;
        }

        // Only index vendors with schedule address and availability
        $hasScheduleAddress = $vendor->addresses()
            ->where('address_type', AddressType::SCHEDULE_ADDRESS)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->exists();

        $hasScheduleAvailability = $vendor->scheduleAvailable()
            ->where('is_enabled', true)
            ->exists();

        return $vendor->user?->hasVerifiedPhoneNumber() &&
            $vendor->user?->hasVerifiedEmail() &&
            $vendor->all_documents_verified &&
            $vendor->iban != null &&
            $vendor->invoice_workspace != null &&
            str_contains($vendor->at_user ?? '', '/') &&
            $hasScheduleAddress &&
            $hasScheduleAvailability;
    }
}
